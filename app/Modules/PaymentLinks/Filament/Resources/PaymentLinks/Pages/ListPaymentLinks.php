<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\Pages;

use App\Modules\Identity\Filament\Concerns\TenantPanel;
use App\Modules\PaymentLinks\Actions\CreatePaymentLink;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\PaymentLinkResource;
use App\Modules\PaymentLinks\Filament\Support\PaymentLinkForm;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkInputParser;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\CurrencyLimits;
use App\Modules\Tenancy\Data\TenantSettings;
use App\Modules\Tenancy\Enums\CheckoutLocale;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Support\Filament\DomainErrors;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Links list with the manual create form (plan 27 Phase 3). The form feeds
 * the same parser and action as `POST /v1/payment_links`, so the rules and
 * limits are identical; refusals come back as field errors or a
 * notification, in the viewer's language.
 */
final class ListPaymentLinks extends ListRecords
{
    protected static string $resource = PaymentLinkResource::class;

    public function getSubheading(): string
    {
        return __('payment_links.page.subheading.'.TenantPanel::modeKey());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('payment_links.actions.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->authorize('create', PaymentLink::class)
                ->modalHeading(__('payment_links.actions.create'))
                ->modalDescription(fn (): string => __('payment_links.actions.create_help.'.TenantPanel::modeKey()))
                ->modalSubmitActionLabel(__('payment_links.actions.create_submit'))
                ->modalWidth('2xl')
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 3])->schema([
                        TextInput::make('amount')
                            ->label(__('payment_links.fields.amount'))
                            ->helperText(__('payment_links.fields.amount_help'))
                            ->placeholder('0.00')
                            ->inputMode('decimal')
                            ->prefix('$')
                            ->suffix(static fn (Get $get): string => is_string($get('currency')) ? $get('currency') : '')
                            ->extraInputAttributes(['class' => 'amount text-end'])
                            ->required()
                            ->validationMessages(['required' => __('payment_links.validation.amount_required')])
                            ->maxLength(20)
                            ->columnSpan(['default' => 1, 'sm' => 2]),
                        Select::make('currency')
                            ->label(__('payment_links.fields.currency'))
                            ->options(CurrencyCode::options(app(CurrencyLimits::class)->enabled()))
                            ->default(CurrencyCode::MXN->value)
                            ->selectablePlaceholder(false)
                            ->live()
                            ->required(),
                    ]),
                    Textarea::make('description')
                        ->label(__('payment_links.fields.description'))
                        ->helperText(__('payment_links.fields.description_help'))
                        ->required()
                        ->validationMessages(['required' => __('payment_links.validation.description_required')])
                        ->maxLength(PaymentLinkInputParser::DESCRIPTION_MAX)
                        ->rows(3),
                    Grid::make(['default' => 1, 'sm' => 2])->schema([
                        Select::make('expiry')
                            ->label(__('payment_links.fields.expiry'))
                            ->options(fn (): array => PaymentLinkForm::expiryOptions(self::settings()))
                            ->default(fn (): int|string => PaymentLinkForm::defaultExpiry(self::settings()))
                            ->selectablePlaceholder(false)
                            ->live(),
                        TextInput::make('expires_in_hours')
                            ->label(__('payment_links.fields.expires_in_hours'))
                            ->helperText(fn (): string => self::expirationHelp())
                            ->integer()
                            ->minValue(1)
                            ->required(static fn (Get $get): bool => $get('expiry') === PaymentLinkForm::CUSTOM_EXPIRY)
                            ->visible(static fn (Get $get): bool => $get('expiry') === PaymentLinkForm::CUSTOM_EXPIRY),
                    ]),
                    Section::make(__('payment_links.sections.more_options'))
                        ->collapsible()
                        ->collapsed()
                        ->compact()
                        ->schema([
                            TextInput::make('client_reference_id')
                                ->label(__('payment_links.fields.client_reference_id'))
                                ->helperText(__('payment_links.fields.client_reference_id_help'))
                                ->maxLength(PaymentLinkInputParser::CLIENT_REFERENCE_MAX),
                            Select::make('locale')
                                ->label(__('payment_links.fields.locale'))
                                ->helperText(__('payment_links.fields.locale_help'))
                                ->options(CheckoutLocale::options()),
                            KeyValue::make('metadata')
                                ->label(__('payment_links.fields.metadata'))
                                ->helperText(__('payment_links.fields.metadata_help', ['max' => PaymentLinkInputParser::METADATA_MAX_KEYS]))
                                ->keyLabel(__('payment_links.fields.metadata_key'))
                                ->valueLabel(__('payment_links.fields.metadata_value'))
                                ->addActionLabel(__('payment_links.fields.metadata_add'))
                                ->rules(['array', 'max:'.PaymentLinkInputParser::METADATA_MAX_KEYS]),
                        ]),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        $link = app(CreatePaymentLink::class)->handleForUser(
                            TenantPanel::user(),
                            app(PaymentLinkInputParser::class)->parse(PaymentLinkForm::toInput($data)),
                        );
                    } catch (ApiException $e) {
                        // The panel accepts thousands separators (the API does not).
                        DomainErrors::fail($action, $e, PaymentLinkForm::FIELDS, [
                            ApiErrorCode::AmountInvalid->value => __('payment_links.validation.amount_format'),
                        ]);
                    }

                    Notification::make()->success()->title(__('payment_links.notifications.created'))->send();
                    $this->redirect(PaymentLinkResource::getUrl('view', ['record' => $link]));
                }),
        ];
    }

    private static function settings(): TenantSettings
    {
        return app(TenantAccess::class)->settings(TenantPanel::user()->tenant_id);
    }

    private static function expirationHelp(): string
    {
        $settings = self::settings();

        return __('payment_links.fields.expires_in_hours_help', [
            'default' => $settings->defaultExpirationHours,
            'max' => $settings->maxExpirationHours,
        ]);
    }
}

"""WCAG contrast of text-fg and text-<status> on *-subtle backgrounds, light
and dark, over page and surface. Values MIRROR resources/css/tokens/
{primitives,semantic}.css: update them here when a token changes.
Run: python3 tools/viewport-check/contrast.py"""
def hx(h): h=h.lstrip('#'); return tuple(int(h[i:i+2],16) for i in (0,2,4))
def lum(c):
    def ch(v):
        v/=255; return v/12.92 if v<=0.04045 else ((v+0.055)/1.055)**2.4
    r,g,b=map(ch,c); return 0.2126*r+0.7152*g+0.0722*b
def cr(a,b):
    la,lb=sorted([lum(a),lum(b)],reverse=True); return (la+0.05)/(lb+0.05)
def over(rgba,base):
    r,g,b,a=rgba; return tuple(round(a*c+(1-a)*bc) for c,bc in zip((r,g,b),base))
P=dict(neutral0='#ffffff',neutral50='#f8f9f9',neutral900='#0d1017',neutral700='#171d2b',
 green50='#edf9f4',green700='#177b4e',green400='#60ca9a',amber50='#fdf3e7',amber700='#b45309',amber400='#f0a94e',
 red50='#fbeaea',red700='#b91c1c',red400='#e5695f',blue50='#edf3fb',blue600='#1a54a9',blue400='#5e8fd7',blue300='#8fb1e3')
light={'fg':P['neutral900'],'bgs':{'page':P['neutral0'],'surface':P['neutral50']},
 'status':{'success':(P['green700'],P['green50']),'warning':(P['amber700'],P['amber50']),'error':(P['red700'],P['red50']),'info':(P['blue600'],P['blue50'])}}
dark={'fg':P['neutral50'],'bgs':{'page':P['neutral900'],'surface':P['neutral700']},
 'status':{'success':(P['green400'],(34,181,115,0.12)),'warning':(P['amber400'],(180,83,9,0.15)),'error':(P['red400'],(185,28,28,0.15)),'info':(P['blue300'],(31,99,199,0.15))}}
worst=99
for name,t in (('light',light),('dark',dark)):
    for base_name,base in t['bgs'].items():
        for st,(fg,sub) in t['status'].items():
            bg = hx(sub) if isinstance(sub,str) else over(sub,hx(base))
            a=cr(hx(t['fg']),bg); b=cr(hx(fg),bg); worst=min(worst,a,b)
            flag=lambda v:'OK ' if v>=4.5 else 'LOW'
            print(f"{name:5} on-{base_name:7} {st:8} bg=#{bg[0]:02x}{bg[1]:02x}{bg[2]:02x}  text-fg {a:5.2f} {flag(a)} | text-{st} {b:5.2f} {flag(b)}")
print('worst', round(worst,2))

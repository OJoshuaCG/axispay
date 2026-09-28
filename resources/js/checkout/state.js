/**
 * The page script's small mutable state, changed only through setState(),
 * so every module reads the same values.
 */
export function createState(initial) {
    const state = { ...initial };

    return {
        get: (key) => state[key],
        setState: (patch) => Object.assign(state, patch),
    };
}

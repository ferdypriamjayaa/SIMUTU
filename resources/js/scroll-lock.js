/*
 * Page scroll lock for modals.
 *
 * Hiding the page's scrollbar would widen the page and make it jump
 * sideways, so its width is put back as padding while the lock is held.
 * Locks nest: the page scrolls again once every holder has released it.
 */
let holders = 0;

export function lockScroll() {
    holders += 1;

    if (holders > 1) {
        return;
    }

    const body = document.body;
    const scrollbar = window.innerWidth - document.documentElement.clientWidth;

    body.style.overflow = 'hidden';
    if (scrollbar > 0) {
        body.style.paddingRight = `${scrollbar}px`;
    }
}

export function unlockScroll() {
    holders = Math.max(0, holders - 1);

    if (holders > 0) {
        return;
    }

    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
}

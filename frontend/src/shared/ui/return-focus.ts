/*
 * Hand focus back to whatever opened a dialog.
 *
 * Radix returns focus on close to the dialog's own Trigger — and to nothing when the dialog was opened by setting state
 * from an ordinary button, which is how nearly every dialog here is opened. A keyboard user who pressed Escape then
 * landed on the page body and had to Tab from the top again.
 *
 * The last element focused outside any dialog is tracked as focus moves, because by the time a dialog's own open hook
 * runs its first field has already taken focus. Closing a dialog returns there if that element is still on the page,
 * and otherwise leaves Radix's default alone.
 */
let lastOutside: HTMLElement | null = null;

if (typeof document !== 'undefined') {
  document.addEventListener('focusin', (event) => {
    const target = event.target;

    if (
      target instanceof HTMLElement &&
      target.closest('[role="dialog"], [role="alertdialog"]') === null
    ) {
      lastOutside = target;
    }
  });
}

/** Pass as `onCloseAutoFocus` on a Radix dialog's content. */
export function returnFocus(event: Event): void {
  if (lastOutside?.isConnected === true) {
    event.preventDefault();
    lastOutside.focus();
  }
}

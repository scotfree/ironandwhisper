/**
 * Presence, drawn.
 *
 * Presence is the one quantity in the game — both sides accumulate it in a
 * town and the higher total wins — so it is drawn the same way wherever it is
 * read: a yellow disc with the number in it, black on gold, whether the
 * presence came from cards or from troops.
 *
 * The point is the contrast with a *count*. A pile of four cards worth three
 * between them shows two numbers side by side, and before this they looked
 * alike; now only one of them is a disc. A count of troops is deliberately
 * left plain for the same reason, even though at `unit.presence` 1 it happens
 * to equal the presence those troops carry — see `BoardView.troopsHtml`.
 */
export function presenceHtml(
    value: number | string,
    extraClass = '',
    title = '',
): string {
    return `<span class="iaw-presence${extraClass ? ` ${extraClass}` : ''}"${
        title ? ` title="${title}"` : ''}>${value}</span>`;
}

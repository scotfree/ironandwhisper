/**
 * Agreeing to end the game, which both turn states offer in the same words.
 *
 * It is deliberately not called a pass. A pass that skipped your turn would
 * stop the deck draining, and the deck is the clock — two players could stall
 * forever, which is the failure the clock was designed to prevent. This is a
 * standing offer: when both are up the game ends and every remaining town
 * resolves at once, exactly as running the deck out does.
 *
 * The offer travels with the turn rather than as an action of its own, so it
 * cannot be made or withdrawn out of turn, and it survives until withdrawn.
 */

export function endOfferLabel(offered: boolean): string {
    return offered ? _('Withdraw offer to end') : _('Offer to end the game');
}

export function endOfferHtml(offered: boolean, opponentOffered: boolean): string {
    if (offered && opponentOffered) {
        return `<div class="iaw-warning"><b>${_('Confirming ends the game.')}</b>
                ${_('Your opponent has already offered, so every remaining town resolves at once — at the presence standing in it today.')}</div>`;
    }
    if (offered) {
        return `<div class="iaw-hint"><b>${_('Offering to end.')}</b>
                ${_('The game stops when your opponent offers too, and every remaining town resolves at once.')}</div>`;
    }
    if (opponentOffered) {
        return `<div class="iaw-hint"><b>${_('Your opponent has offered to end the game.')}</b>
                ${_('Offer as well and it stops here, resolving every remaining town at once.')}</div>`;
    }
    return '';
}

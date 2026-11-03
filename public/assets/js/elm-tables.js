/*
 * Lettie-DGDA — définition centralisée des colonnes des tableaux serveur.
 * Chaque colonne : data (nom serveur, sert au tri), title, html(row) (affichage, ÉCHAPPÉ), text(row) (export),
 * orderable, className. Toute colonne triable figure dans MailRepository::SORTABLE.
 */
(function (root, factory) {
    const isNode = typeof module === 'object' && module.exports;
    const core = isNode ? require('./elm-core.js') : root.ELM.core;
    const mod = factory(core);
    if (isNode) {
        module.exports = mod;
    } else {
        root.ELM.tables = mod;
    }
})(typeof self !== 'undefined' ? self : this, function (core) {
    'use strict';

    const esc = core.escapeHtml;
    /** Même code couleur que partials/status_badge.php */
    const STATUS_BADGE = {
        registered: 'waiting_part', assigned: 'maintenance', in_progress: 'maintenance', answered: 'available',
        closed: 'immobilized', cancelled: 'immobilized', draft: 'waiting_part', sent: 'available',
    };
    const PRIORITY_BADGE = { urgent: 'waiting_part', very_urgent: 'breakdown' };
    const LEVELS = ['normal', 'confidential', 'secret'];

    /** @param {{t:Function, locale:string}} ctx */
    function definitions(ctx) {
        const t = ctx.t;
        const today = new Date().toISOString().slice(0, 10);
        const isLate = (r) => r.due_on && r.due_on < today && ['answered', 'closed', 'sent', 'cancelled'].indexOf(r.status) === -1;
        const masked = () => '<span class="muted">' + esc(t('mail.masked')) + '</span>';
        const level = (r) => (Number(r.confidentiality) > 0
            ? ' <span class="badge badge--breakdown">' + esc(t('mail.level.' + LEVELS[Number(r.confidentiality)])) + '</span>' : '');

        return {
            mails: [
                {
                    data: 'reference', title: t('mail.col.reference'), orderable: true,
                    html: (r) => '<strong>' + esc(r.reference) + '</strong>' + level(r) + (r.external_ref ? '<br><span class="ext-ref">' + esc(r.external_ref) + '</span>' : ''),
                    text: (r) => r.reference,
                },
                {
                    data: 'event_at', title: t('mail.col.date'), orderable: true,
                    html: (r) => esc(core.formatDate(r.event_at, true)),
                    text: (r) => r.event_at, type: 'date',
                },
                {
                    data: 'correspondent', title: t('mail.col.correspondent'), orderable: true,
                    html: (r) => (Number(r.readable) ? esc(r.correspondent) : masked()),
                    text: (r) => (Number(r.readable) ? r.correspondent : t('mail.masked')),
                },
                {
                    data: 'subject', title: t('mail.col.subject'), orderable: true,
                    html: (r) => (Number(r.readable) ? esc(r.subject) : masked()),
                    text: (r) => (Number(r.readable) ? r.subject : t('mail.masked')),
                },
                {
                    data: 'unit', title: t('mail.col.unit'), orderable: true,
                    html: (r) => esc(r.unit),
                    text: (r) => r.unit,
                },
                {
                    data: 'priority', title: t('mail.col.priority'), orderable: true,
                    html: (r) => (PRIORITY_BADGE[r.priority] ? '<span class="badge badge--' + PRIORITY_BADGE[r.priority] + '">' + esc(t('mail.priority.' + r.priority)) + '</span>' : '<span class="muted">' + esc(t('mail.priority.normal')) + '</span>'),
                    text: (r) => t('mail.priority.' + r.priority),
                },
                {
                    data: 'due_on', title: t('mail.col.due'), orderable: true,
                    html: (r) => (r.due_on ? '<span class="' + (isLate(r) ? 'text-bad' : '') + '">' + esc(core.formatDate(r.due_on)) + '</span>' : '<span class="muted">—</span>'),
                    text: (r) => r.due_on || '', type: 'date',
                },
                {
                    data: 'ack_at', title: t('mail.col.ack'), orderable: false,
                    html: (r) => (r.direction !== 'in' ? '<span class="muted">—</span>' : esc(r.ack_at ? core.formatDate(r.ack_at) : t('track.ack_no'))),
                    text: (r) => (r.ack_at ? r.ack_at : ''),
                },
                {
                    data: 'status', title: t('mail.col.status'), orderable: true,
                    html: (r) => '<span class="badge badge--' + (STATUS_BADGE[r.status] || 'neutral') + '">' + esc(t('mail.status.' + r.status)) + '</span>',
                    text: (r) => t('mail.status.' + r.status),
                },
            ],
        };
    }

    return { definitions };
});

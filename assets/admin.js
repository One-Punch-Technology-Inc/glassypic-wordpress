/* global glassyPic, jQuery */
(function ($) {
    'use strict';

    // Single-image optimize button
    $(document).on('click', '.glassypic-optimize-single', function (e) {
        e.preventDefault();
        var btn = $(this);
        var id  = btn.data('id');
        btn.prop('disabled', true).text('Queuing…');

        $.post(glassyPic.ajaxUrl, {
            action:        'glassypic_optimize_single',
            nonce:         glassyPic.nonce,
            attachment_id: id,
        }).done(function () {
            btn.text('Queued ✓');
        }).fail(function () {
            btn.prop('disabled', false).text('Retry');
        });
    });

    // Restore original button
    $(document).on('click', '.glassypic-restore-original', function (e) {
        e.preventDefault();
        // eslint-disable-next-line no-alert
        if ( ! window.confirm(glassyPic.restoreConfirm)) { return; }
        var btn = $(this);
        var id  = btn.data('id');
        btn.prop('disabled', true).text('Restoring…');

        $.post(glassyPic.ajaxUrl, {
            action:        'glassypic_restore_original',
            nonce:         glassyPic.nonce,
            attachment_id: id,
        }).done(function () {
            btn.closest('.misc-pub-section').html('<em>Restored ✓ — refresh to see original</em>');
        }).fail(function () {
            btn.prop('disabled', false).text('Restore Original');
        });
    });

    // Bulk optimize page
    var bulkRunning = false;
    var pollInterval;

    function updateProgress(data) {
        var done  = data.completed;
        var total = data.total;
        var pct   = total > 0 ? Math.round((done / total) * 100) : 0;
        $('#glassypic-progress').attr({ value: done, max: total });
        $('#glassypic-progress-text').text(done + ' / ' + total + ' optimized (' + pct + '%)');
        if (data.pending === 0 && data.processing === 0 && bulkRunning) {
            clearInterval(pollInterval);
            bulkRunning = false;
            $('#glassypic-bulk-start').prop('disabled', false).text('All done ✓');
        }
    }

    function startPolling() {
        clearInterval(pollInterval);
        pollInterval = setInterval(function () {
            $.post(glassyPic.ajaxUrl, { action: 'glassypic_bulk_status', nonce: glassyPic.nonce })
             .done(function (res) { if (res.success) updateProgress(res.data); });
        }, 3000);
    }

    $('#glassypic-bulk-start').on('click', function () {
        var btn = $(this);
        btn.prop('disabled', true).text('Starting…');
        $('#glassypic-progress-bar').show();
        bulkRunning = true;

        $.post(glassyPic.ajaxUrl, { action: 'glassypic_bulk_queue', nonce: glassyPic.nonce, retry_failed: 0 })
         .done(function (res) {
             if (res.success) { startPolling(); btn.text('Optimizing…'); }
         });
    });

    $('#glassypic-bulk-retry').on('click', function () {
        $.post(glassyPic.ajaxUrl, { action: 'glassypic_bulk_queue', nonce: glassyPic.nonce, retry_failed: 1 })
         .done(function (res) { if (res.success) { bulkRunning = true; startPolling(); } });
    });

}(jQuery));

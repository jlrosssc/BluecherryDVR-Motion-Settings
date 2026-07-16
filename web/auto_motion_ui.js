function autoMotionMapApplyToGrid(map) {
    var cells = $('.grid-bl .table-grid td');
    var classes = ['bg-default', 'bg-success', 'bg-info', 'bg-primary', 'bg-warning', 'bg-danger'];

    if (!map || cells.length !== map.length) {
        $.notify({icon: 'fa fa-times-circle fa-fw', message: 'Auto-detect map does not match this camera grid.'}, {type: 'danger', delay: 5000});
        return false;
    }

    cells.each(function(i) {
        var level = parseInt(map.charAt(i), 10);
        var cls = classes[level] || classes[0];
        $(this)
            .removeClass(classes.join(' '))
            .addClass(cls)
            .attr('data-type', level);
    });

    $('#motion-map').val(map);
    return true;
}

function autoMotionMapCountsText(counts) {
    var labels = {'0': 'off', '1': 'minimal', '2': 'low', '3': 'average', '4': 'high', '5': 'very high'};
    var parts = [];
    $.each(labels, function(level, label) {
        if (counts && counts[level] !== undefined) parts.push(label + ': ' + counts[level]);
    });
    return parts.join(', ');
}

function autoMotionMapRun(button) {
    var form = $('#motion-submit');
    var status = $('#auto-motion-status');
    var id = form.find('input[name="id"]').val();
    var sensitivity = $('#auto-motion-sensitivity').val();
    var noise = $('#auto-motion-noise').val();

    button.button('loading');
    status.removeClass('text-danger text-success').addClass('text-muted').text('Analyzing recent recordings...');

    $.ajax({
        type: 'POST',
        url: '/ajax/automotionmap.php',
        dataType: 'json',
        data: {
            id: id,
            sensitivity: sensitivity,
            noise_suppression: noise,
            samples: 4,
            frames_per_video: 4
        }
    }).done(function(msg) {
        if (parseInt(msg.status, 10) === 6 && msg.data && autoMotionMapApplyToGrid(msg.data.motion_map)) {
            status.removeClass('text-muted text-danger').addClass('text-success')
                .text('Loaded proposed map. Review/edit the grid, then click Save Changes. Proposed: ' + autoMotionMapCountsText(msg.data.proposed_counts));
            $.notify({icon: 'fa fa-check fa-fw', message: msg.msg}, {type: 'success', delay: 6000});
        } else {
            status.removeClass('text-muted text-success').addClass('text-danger').text(msg.msg || 'Auto detect failed.');
            $.notify({icon: 'fa fa-times-circle fa-fw', message: msg.msg || 'Auto detect failed.'}, {type: 'danger', delay: 8000});
        }
    }).fail(function() {
        status.removeClass('text-muted text-success').addClass('text-danger').text('Auto detect request failed.');
        $.notify({icon: 'fa fa-times-circle fa-fw', message: 'Auto detect request failed.'}, {type: 'danger', delay: 8000});
    }).always(function() {
        button.button('reset');
    });
}

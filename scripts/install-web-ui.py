#!/usr/bin/env python3
"""Install the optional Bluecherry web UI integration.

Run as root from a cloned repository on the Bluecherry server:

    sudo python3 scripts/install-web-ui.py

The installer backs up the Bluecherry files it modifies before adding the
"Recommend Motion Sensitivity" panel to the motion-map page.
"""

import argparse
import datetime as dt
import os
import shutil
import subprocess
from pathlib import Path


def install(args):
    repo = Path(__file__).resolve().parents[1]
    stamp = dt.datetime.now().strftime("%Y%m%d-%H%M%S")

    template = Path(args.bluecherry_www) / "template/ajax/motionmap.php"
    devices_js = Path(args.bluecherry_www) / "template/dist/js/devices.js"
    ajax_php = Path(args.bluecherry_www) / "ajax/automotionmap.php"
    optimizer_web = Path(args.sbin_dir) / "bluecherry-motion-optimizer-web"
    helper = Path(args.sbin_dir) / "bluecherry-auto-motion-helper"
    work_dir = Path(args.work_dir)

    for path in (template, devices_js):
        if not path.exists():
            raise SystemExit("Missing Bluecherry file: {}".format(path))
        backup = path.with_name(path.name + ".bak-auto-motion-" + stamp)
        shutil.copy2(str(path), str(backup))

    shutil.copy2(str(repo / "web/automotionmap.php"), str(ajax_php))
    os.chmod(str(ajax_php), 0o644)

    shutil.copy2(str(repo / "bin/bluecherry-motion-optimizer"), str(optimizer_web))
    os.chmod(str(optimizer_web), 0o755)

    subprocess.check_call([
        "gcc",
        "-Wall",
        "-Wextra",
        "-O2",
        "-o",
        str(helper),
        str(repo / "helper/bluecherry_auto_motion_helper.c"),
    ])
    os.chown(str(helper), 0, 0)
    os.chmod(str(helper), 0o4755)

    work_dir.mkdir(parents=True, exist_ok=True)
    os.chmod(str(work_dir), 0o775)

    ui = (repo / "web/auto_motion_ui.js").read_text()
    js_text = devices_js.read_text()
    marker_start = "/* Auto Motion Detection Optimizer: start */"
    marker_end = "/* Auto Motion Detection Optimizer: end */"
    block = "\n{}\n{}\n{}\n".format(marker_start, ui, marker_end)
    if marker_start not in js_text:
        devices_js.write_text(js_text.rstrip() + "\n" + block)

    tpl = template.read_text()
    if 'id="auto-motion-detect"' not in tpl:
        insert = """

        <div class="panel panel-default">
            <div class="panel-heading">Recommend Motion Sensitivity</div>
            <div class="panel-body">
                <div class="form-group">
                    <label class="col-lg-2 col-md-2 control-label">Sensitivity</label>
                    <div class="col-lg-2 col-md-2">
                        <input type="number" class="form-control" id="auto-motion-sensitivity" min="1" max="10" value="5" />
                    </div>

                    <label class="col-lg-2 col-md-2 control-label">Noise Filter</label>
                    <div class="col-lg-2 col-md-2">
                        <input type="number" class="form-control" id="auto-motion-noise" min="0" max="10" value="5" />
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-lg-2 col-md-2 control-label">Scan Type</label>
                    <div class="col-lg-3 col-md-3">
                        <select class="form-control" id="auto-motion-scan-mode">
                            <option value="quick" selected="selected">Quick Scan</option>
                            <option value="deep">Deep Scan</option>
                        </select>
                    </div>

                    <label class="col-lg-2 col-md-2 control-label">Deep Hours</label>
                    <div class="col-lg-2 col-md-2">
                        <input type="number" class="form-control" id="auto-motion-deep-hours" min="24" max="168" value="24" disabled="disabled" />
                    </div>

                    <div class="col-lg-3 col-md-3">
                        <button type="button" class="btn btn-primary click-event" id="auto-motion-detect" data-function="autoMotionMapRun" data-loading-text="Analyzing...">
                            <i class="fa fa-magic fa-fw"></i> Recommend Motion Sensitivity
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <div class="col-lg-12 col-md-12">
                        <span id="auto-motion-status" class="text-muted">Quick Scan uses recent recordings. Deep Scan samples 24-168 hours and may take several minutes. Review/edit, then click Save Changes.</span>
                    </div>
                </div>
            </div>
        </div>
"""
        needle = """        <div class="panel panel-default">
            <div class="panel-body">
                <div class="col-lg-7 col-md-7">"""
        if needle not in tpl:
            raise SystemExit("Could not find motion map image panel insertion point")
        template.write_text(tpl.replace(needle, insert + "\n" + needle, 1))

    tpl = template.read_text()
    marker = "/* Recommend Motion Sensitivity nowdoc: start */"
    if marker not in tpl:
        nowdoc = r"""

addJs(<<<'JS'
$(function() {
    /* Recommend Motion Sensitivity nowdoc: start */
    function recommendMotionApplyToGrid(map) {
        var cells = $(".grid-bl .table-grid td");
        var classes = ["bg-default", "bg-success", "bg-info", "bg-primary", "bg-warning", "bg-danger"];
        if (!map || cells.length !== map.length) {
            $("#auto-motion-status").removeClass("text-muted text-success").addClass("text-danger").text("Recommended map does not match this camera grid.");
            return false;
        }
        cells.each(function(i) {
            var level = parseInt(map.charAt(i), 10);
            var cls = classes[level] || classes[0];
            $(this).removeClass(classes.join(" "));
            $(this).addClass(cls).attr("data-type", level);
        });
        $("#motion-map").val(map);
        return true;
    }

    function recommendMotionCountsText(counts) {
        var labels = {"0": "off", "1": "minimal", "2": "low", "3": "average", "4": "high", "5": "very high"};
        var parts = [];
        $.each(labels, function(level, label) {
            if (counts && counts[level] !== undefined) parts.push(label + ": " + counts[level]);
        });
        return parts.join(", ");
    }

    $("#auto-motion-scan-mode").off("change.recommendMotionMode").on("change.recommendMotionMode", function() {
        var deep = $(this).val() === "deep";
        $("#auto-motion-deep-hours").prop("disabled", !deep);
        $("#auto-motion-status").removeClass("text-danger text-success").addClass("text-muted")
            .text(deep ? "Deep Scan samples 24-168 hours and may take several minutes." : "Quick Scan uses recent recordings. Review/edit, then click Save Changes.");
    });

    $("#auto-motion-detect").off("click.recommendMotion").on("click.recommendMotion", function(e) {
        e.preventDefault();
        e.stopPropagation();
        var button = $(this);
        var status = $("#auto-motion-status");
        var id = $("#motion-submit").find("input[name=id]").val();
        var mode = $("#auto-motion-scan-mode").val();
        var deepHours = parseInt($("#auto-motion-deep-hours").val(), 10) || 24;
        button.button("loading");
        if (mode === "deep") {
            deepHours = Math.max(24, Math.min(168, deepHours));
            $("#auto-motion-deep-hours").val(deepHours);
            if (!confirm("Deep Scan will analyze recordings across " + deepHours + " hours and may take several minutes. Continue?")) {
                button.button("reset");
                return false;
            }
            status.removeClass("text-danger text-success").addClass("text-muted").text("Deep Scan analyzing " + deepHours + " hours of recordings...");
        } else {
            status.removeClass("text-danger text-success").addClass("text-muted").text("Quick Scan analyzing recent recordings...");
        }
        $.ajax({
            type: "POST",
            url: "/ajax/automotionmap.php",
            dataType: "json",
            data: {
                id: id,
                sensitivity: $("#auto-motion-sensitivity").val(),
                noise_suppression: $("#auto-motion-noise").val(),
                scan_mode: mode,
                deep_hours: deepHours,
                samples: (mode === "deep" ? 24 : 2),
                frames_per_video: (mode === "deep" ? 3 : 2)
            }
        }).done(function(msg) {
            if (parseInt(msg.status, 10) === 6 && msg.data && recommendMotionApplyToGrid(msg.data.motion_map)) {
                status.removeClass("text-muted text-danger").addClass("text-success")
                    .text("Loaded recommendation. Review/edit the grid, then click Save Changes. Proposed: " + recommendMotionCountsText(msg.data.proposed_counts));
                if ($.notify) $.notify({icon: "fa fa-check fa-fw", message: msg.msg}, {type: "success", delay: 6000});
            } else {
                status.removeClass("text-muted text-success").addClass("text-danger").text((msg && msg.msg) ? msg.msg : "Recommendation failed.");
                if ($.notify) $.notify({icon: "fa fa-times-circle fa-fw", message: (msg && msg.msg) ? msg.msg : "Recommendation failed."}, {type: "danger", delay: 8000});
            }
        }).fail(function(xhr) {
            var message = "Recommendation request failed.";
            if (xhr && xhr.responseText) message += " " + xhr.responseText.substring(0, 180);
            status.removeClass("text-muted text-success").addClass("text-danger").text(message);
            if ($.notify) $.notify({icon: "fa fa-times-circle fa-fw", message: message}, {type: "danger", delay: 8000});
        }).always(function() {
            button.button("reset");
        });
    });
    /* Recommend Motion Sensitivity nowdoc: end */
});
JS
);
"""
        pos = tpl.rfind("?>")
        if pos == -1:
            raise SystemExit("Could not find PHP close tag")
        template.write_text(tpl[:pos] + nowdoc + "\n" + tpl[pos:])

    tpl = template.read_text()
    background_marker = "/* Recommend Motion Sensitivity background job: start */"
    if background_marker not in tpl:
        background = (repo / "web/background_job_ui.phpfrag").read_text()
        pos = tpl.rfind("?>")
        if pos == -1:
            raise SystemExit("Could not find PHP close tag")
        template.write_text(tpl[:pos] + "\n" + background + "\n" + tpl[pos:])

    print("Installed Bluecherry auto motion UI.")
    print("Backups stamped: {}".format(stamp))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--bluecherry-www", default="/usr/share/bluecherry/www")
    parser.add_argument("--sbin-dir", default="/usr/local/sbin")
    parser.add_argument("--work-dir", default="/var/lib/bluecherry/motion-optimizer")
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise SystemExit("Run as root.")
    install(args)


if __name__ == "__main__":
    main()

# BluecherryDVR Motion Settings

BluecherryDVR Motion Settings analyzes recent Bluecherry recordings and generates proposed `Devices.motion_map` settings. It can be used as a command-line dry-run tool, installed into the Bluecherry motion-map page as a **Recommend Motion Sensitivity** button, or run from the Devices page as **Auto Detect Motion Settings All Cameras**.

The single-camera web UI does not save automatically. It loads the proposed map into Bluecherry's existing grid so you can review and edit it, then use Bluecherry's normal **Save Changes** button. The all-camera Devices page action runs in the background and automatically saves optimized maps for every camera, creating rollback JSON files first.

## Important Timing Note

This tool recommends settings from motion that already exists in recorded video. Run it only after the camera has had enough time to record representative activity for that location.

For a new camera or recently moved camera, wait at least 24 hours before using the recommendation. For better results, wait several days so the sample includes normal daytime, nighttime, weather, shadows, vehicles, people, pets, trees, and other recurring motion.

If the available recordings only include quiet periods, the tool may recommend too many inactive cells. If they only include storms, wind, or unusual activity, it may recommend settings that are too sensitive or too suppressed.

## Recommended Analysis Baseline

For a new install or a camera that has not been tuned yet, start the optimizer with **Sensitivity** set high, usually `8`, and **Noise Filter** around `5`. This gives the analyzer a broad view of motion in the recordings without immediately treating tiny compression noise or lighting flicker as important activity.

The web UI does not overwrite the saved Bluecherry motion map during analysis. It loads a proposed map into the grid for review. Edit the proposed grid if needed, then click Bluecherry's normal **Save Changes** button only when you are satisfied with the result.

## Requirements

- Bluecherry DVR with MySQL configuration at `/etc/bluecherry.conf`
- Python 3
- Docker access for reading `/var/lib/bluecherry/recordings` when the current user cannot read recordings directly
- Bluecherry's bundled ffmpeg at `/usr/lib/bluecherry/ffmpeg`
- `gcc` only for the optional web UI helper

## Standalone Install

```bash
git clone https://github.com/jlrosssc/BluecherryDVR-Motion-Settings.git
cd BluecherryDVR-Motion-Settings
sudo sh scripts/install-standalone.sh
```

List cameras:

```bash
bluecherry-motion-optimizer list-cameras
```

Run a dry-run analysis:

```bash
bluecherry-motion-optimizer analyze --camera 1 --sensitivity 8 --noise-suppression 5
```

Run a deep scan over a time window:

```bash
bluecherry-motion-optimizer analyze --camera 1 --sensitivity 8 --noise-suppression 5 --lookback-hours 72 --samples 288 --frames-per-video 3
```

Apply the proposed map to the Bluecherry database:

```bash
bluecherry-motion-optimizer analyze --camera 1 --sensitivity 8 --noise-suppression 5 --apply
```

Run optimized analysis for all cameras and automatically save each proposed map:

```bash
bluecherry-motion-optimizer optimize-all --sensitivity 8 --noise-suppression 5
```

The tool creates a rollback JSON file before applying any database change.

## Web UI Install

Run this on the Bluecherry server:

```bash
git clone https://github.com/jlrosssc/BluecherryDVR-Motion-Settings.git
cd BluecherryDVR-Motion-Settings
sudo python3 scripts/install-web-ui.py
```

Then open Bluecherry:

1. Go to the camera motion-map settings page.
2. Set **Sensitivity** to `8` as a recommended starting point and **Noise Filter** to `5`.
3. Choose **Optimized Scan**, **Quick Scan**, or **Deep Scan**. Optimized Scan is the default.
4. For **Optimized Scan**, choose `24-168` hours. It automatically samples in batches and stops when the recommendation stabilizes.
5. For **Deep Scan**, choose `24-168` hours and optionally adjust **Samples/Hour**. It defaults to `4` and is only shown for Deep Scan.
6. Click **Recommend Motion Sensitivity**.
7. Review and edit the grid.
8. Click Bluecherry's normal **Save Changes** button.

Quick Scan uses the newest recordings and is intended for a fast first pass. Optimized Scan samples randomly across the selected lookback window in batches until the recommended map stabilizes or it reaches the sample cap. Deep Scan spreads up to four samples per selected hour across the selected lookback window and usually gives a better recommendation when the camera has enough representative recordings.

Recommendation scans run as background jobs on the Bluecherry server. The web page polls for status and stores the active job id in the browser for that camera, so a long Deep Scan can continue even if the browser briefly disconnects. If the computer sleeps, reopen the same motion-map page and the page will try to reconnect to the last scan for that camera.

On the Devices page, click **Auto Detect Motion Settings All Cameras** to run Optimized Scan for every camera in the background. This action saves each recommended map automatically, reports saved and failed camera counts, and writes rollback files under the optimizer work directory before changing the database.

Use the **Stop** button next to either scan button to cancel a running individual-camera or all-camera background scan.

The optimizer automatically removes temporary copied recordings and extracted frame files after each camera is analyzed. It keeps reports, previews, rollback files, and job status files.

## Tuning

`--sensitivity` accepts `1-10`.

- Lower values produce fewer active cells.
- Higher values preserve more motion-sensitive cells.
- For first-time tuning, start at `8` so the analyzer is less likely to miss meaningful motion.

`--noise-suppression` accepts `0-10`.

- Lower values allow more noisy motion areas.
- Higher values reduce cells that appear constantly active, such as trees or leaves.

Optional zones:

```bash
bluecherry-motion-optimizer analyze --camera 1 --keep-zone 10,8,18,15
bluecherry-motion-optimizer analyze --camera 1 --exclude-zone 0,0,31,4
```

Zone coordinates are Bluecherry grid cells: `x1,y1,x2,y2`.

## License

GPL-2.0. See `LICENSE`.

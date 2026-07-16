# BluecherryDVR Motion Settings

BluecherryDVR Motion Settings analyzes recent Bluecherry recordings and generates a proposed `Devices.motion_map` for a camera. It can be used as a command-line dry-run tool or installed into the Bluecherry motion-map page as a **Recommend Motion Sensitivity** button.

The web UI integration does not save automatically. It loads the proposed map into Bluecherry's existing grid so you can review and edit it, then use Bluecherry's normal **Save Changes** button.

## Important Timing Note

This tool recommends settings from motion that already exists in recorded video. Run it only after the camera has had enough time to record representative activity for that location.

For a new camera or recently moved camera, wait at least 24 hours before using the recommendation. For better results, wait several days so the sample includes normal daytime, nighttime, weather, shadows, vehicles, people, pets, trees, and other recurring motion.

If the available recordings only include quiet periods, the tool may recommend too many inactive cells. If they only include storms, wind, or unusual activity, it may recommend settings that are too sensitive or too suppressed.

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
bluecherry-motion-optimizer analyze --camera 1 --sensitivity 5 --noise-suppression 5
```

Run a deep scan over a time window:

```bash
bluecherry-motion-optimizer analyze --camera 1 --sensitivity 5 --noise-suppression 5 --lookback-hours 72 --samples 72 --frames-per-video 3
```

Apply the proposed map to the Bluecherry database:

```bash
bluecherry-motion-optimizer analyze --camera 1 --sensitivity 5 --noise-suppression 5 --apply
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
2. Set **Sensitivity** and **Noise Filter**.
3. Choose **Quick Scan** or **Deep Scan**.
4. For **Deep Scan**, choose `24-168` hours. It may take several minutes because it samples up to one recording per selected hour across that time window.
5. Click **Recommend Motion Sensitivity**.
6. Review and edit the grid.
7. Click Bluecherry's normal **Save Changes** button.

Quick Scan uses the newest recordings and is intended for a fast first pass. Deep Scan spreads up to one sample per selected hour across the selected lookback window and usually gives a better recommendation when the camera has enough representative recordings.

Recommendation scans run as background jobs on the Bluecherry server. The web page polls for status and stores the active job id in the browser for that camera, so a long Deep Scan can continue even if the browser briefly disconnects. If the computer sleeps, reopen the same motion-map page and the page will try to reconnect to the last scan for that camera.

## Tuning

`--sensitivity` accepts `1-10`.

- Lower values produce fewer active cells.
- Higher values preserve more motion-sensitive cells.

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

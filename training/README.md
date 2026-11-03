# training/ — the scanner's model and the data it learns from

This folder is separate from the app. The app never reads it. It holds:

| Path | What |
|---|---|
| `model/` | The trained model the app runs: `model.npz` (head and gallery), the base image model `encoder.onnx` (DINOv2-small by Meta, Apache 2.0; see `NOTICE-encoder.txt`), and `manifest.json` (what it learned from and how accurate it measured) |
| `data/` | What training needs to carry on without downloading anything again: the vectors of every photo already seen, and the labelled lists of photos (wiki, reference sources) |
| `PACKED.json` | Every file's checksum. Large files are split into parts under 45 MB (GitHub's limits) and text is gzipped; restore rejoins and verifies them |
| `train` | The one command |

**No photos are kept here.** Wiki and reference-source photos are used under
written permission, for training only. Each one is downloaded, turned into
numbers and discarded. Collectors' scan pictures never leave the app; the
privacy policy promises that.

## Train, then update the app

On your computer, with the stack running (`./collectai setup`):

```bash
training/train
```

That one command:

1. restores what's saved here into the app's working folder;
2. exports your own photos and consented scans, and refreshes the photo lists
   (wikis and the five permitted sources);
3. downloads and embeds only the photos it hasn't seen (resumable — stop it any
   time; `training/train --limit 2000` does a bounded run);
4. trains, measures on held-out photos and calibrates when looks alone may name
   a car;
5. saves the result back into this folder.

Your local app uses the new model immediately. To ship it:

```bash
git add training && git commit -m "Retrained scanner model" && git push
```

```bash
ssh root@your-server-ip
```

```bash
cd /opt/collectai && ./collectai deploy
```

`deploy` pulls the code and this folder and publishes the model into the
running app; the recognizer reloads it by itself. To publish without a full
deploy (after `git pull`):

```bash
training/train publish
```

Check the result in `model/manifest.json`. `evaluation` has top-1/top-5
accuracy on held-out versions, and `thresholds` says when looks alone may name
a car (`visual_only`, at the measured precision).

## The model saved here

Trained 2026-11-04 on 46,217 photos of 14,156 cars and products. On held-out
versions it was right first 74.5% of the time and in the top 5 85.4% of the
time. Looks-only answers were 95.1% precise, at 30.4% coverage. It has not yet
been measured on phone photos; your own photos and consented scans fix that the
next time you train.

## Notes

- Every retrain that changes the model adds a new copy to git history
  (roughly the size of `model/`). Retrain when there is meaningfully new data —
  a few hundred confirmed scans or your own photos — not daily.
- The code that does the work is in `recognizer/train/`; this folder is its
  input and output. Full explanation: README §5.10.

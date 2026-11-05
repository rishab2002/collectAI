# Two real photographs of packaged cars

The owner's own, of their own collection, kept here because the numbers in
CLAUDE.md (*Recognition*) and README §5.8 were measured on these two files and
can be re-measured from them. They are exactly as the phone took them,
unscaled: the packaged scan segments at 2400 px but reads each package from
the photo at full size, and scaling these down to 2400 first measurably costs
reads ("2004 SUBARU FORESTER STI" becomes "FORESTER stl"). A fixture that is
not what a phone produces would measure the wrong thing.

They are the real case, not a tidy one: packages overlapping on a carpet and
on a blanket, some face up, some half under the next one, two brands in one
shot, and no plain contrasting background anywhere.

| File | What is in it |
|---|---|
| `premium-two-packs.jpg` | Five Hot Wheels Premium two-packs, overlapping, ten named cars between them. One card's name is printed upside down |
| `matchbox-and-mainline.jpg` | A fan of Matchbox blisters stacked so that all but the top few have their names hidden, plus three larger cards face up |

To re-measure:

```bash
cd recognizer && .venv/bin/python -m collectai_recognizer \
  --index ../storage/app/recognition/catalog-index.json \
  scan ../tests/Fixtures/packaging/premium-two-packs.jpg --layout packaged --all
```

The rest of the photos the owner supplied are **not** in git: they are in
`tests/Fixtures/packaging-photos/`, which is ignored.

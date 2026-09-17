# The page looks broken after I upload a change

If the sidebar text runs down the page, the layout collapses, or a new
feature does nothing, the browser is almost certainly still using the **old
stylesheet or script** it downloaded last time.

## What causes it

Browsers cache `app.css` and the `.js` files aggressively — that is normally
a good thing, it makes the app fast. But it means that after you upload a
change, the browser may keep using yesterday's copy. The HTML is new, the CSS
is old, and the page renders half-styled.

Hostinger also has its own cache in front of your files, so the same file can
be stale in two places at once.

## It is now handled automatically

Every stylesheet and script is loaded with a version stamp taken from the
file's own modification time:

```html
<link rel="stylesheet" href="assets/app.css?v=1789202199">
<script src="assets/scribe.js?v=1789202199"></script>
```

Upload a changed file and the number changes, so the browser treats it as a
new URL and fetches it. Leave a file alone and the number stays the same, so
it keeps being cached. You do not have to do anything.

## If a page still looks wrong

1. **Hard refresh** — `Ctrl + Shift + R` (Windows) or `Cmd + Shift + R` (Mac).
2. **Clear Hostinger's cache** — hPanel → Advanced → Cache Manager → Purge.
3. **Check the file actually uploaded.** Open
   `https://yoursite.com/assets/app.css` directly. It should be about 34 KB
   and end with responsive rules. If it is much shorter, the upload was
   incomplete — upload `assets/` again.

## When uploading, always replace the whole `assets/` folder

The CSS and JS are edited together and depend on each other. Uploading
`app.css` but not `scribe.js` — or the reverse — can leave the app in a state
that is hard to diagnose.

# Documentation workflow

The [public documentation](https://pupitooo.github.io/emorfiq-adapter/)
is published on GitHub Pages. The preview and published site use the same
Material for MkDocs image, theme, navigation, and system fonts.

## Local preview

```sh
make docs
```

Open [http://localhost:8089](http://localhost:8089). Only the documentation
container starts; the web server and scheduler are not required.
To select another port, run `DOCS_PORT=8091 make docs`.
Changes to `README.md` and documentation Markdown rebuild the preview and refresh
the browser automatically. Stop it with `make docs-stop`.

## Content and navigation

The home page is generated from the repository's `README.md` by
`tools/docs/hooks.py`. All topic Markdown files live in `docs/`, with lowercase,
hyphen-separated filenames. Configuration pages are grouped in `docs/configuration/`; development
guides live in `docs/development/`. Execution coordination, recovery, external
effects, and their verification plan live in `docs/execution/`. Architecture and task services are directly
in `docs/`. MkDocs reads them through
`docs_dir: ../../docs` in `tools/docs/mkdocs.yml`; the same file defines the menu.
Configuration, the README hook, and other documentation tooling live in
`tools/docs/`, separately from content.

The JSON reference, loading details, and test cases each have one canonical page.

Keep the README focused on starting the application, starting the documentation,
and a brief architecture overview. Link detailed topics to their own pages.
When adding or expanding content, reconsider page boundaries and navigation.
Use small, focused pages with short, descriptive menu labels so readers can
quickly find the right topic. Keep a single detailed explanation of each topic
and link to it from related pages.

README links to `docs/` are rewritten by the hook to work inside
the site. Use relative Markdown links between topic pages.

## Validate and export

| Command | Purpose |
| --- | --- |
| `make docs` | Start the independent preview and print its URL. |
| `make docs-stop` | Stop the documentation service. |
| `make docs-check` | Validate links, navigation, and build the site in strict mode. |
| `make docs-export` | Build the static site in `build/docs/`. |

CI runs `make docs-check` on pull requests. Run it after changing pages,
navigation, or README links.

## GitHub Pages

In repository **Settings → Pages → Build and deployment**, set **Source** to
**GitHub Actions**. The `Documentation Pages` workflow builds and publishes the
site when changes reach `main`, or when manually run on `main`.

The default publication address is
[https://pupitooo.github.io/emorfiq-adapter/](https://pupitooo.github.io/emorfiq-adapter/).
The workflow reads the actual Pages URL, including its repository path or custom
domain. To export for another address locally, use:

```sh
DOCS_SITE_URL=https://example.com/docs/ make docs-export
```

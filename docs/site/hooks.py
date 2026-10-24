"""Publish the repository README as the documentation home page."""

from pathlib import Path

from mkdocs.structure.files import File

README_PATH = Path(__file__).resolve().parents[2] / "README.md"


def on_files(files, config):
    """Generate the home page and resolve its documentation links within the site."""
    markdown = README_PATH.read_text(encoding="utf-8")
    markdown = markdown.replace("](docs/site/content/", "](")
    files.append(File.generated(config, "index.md", content=markdown))
    return files

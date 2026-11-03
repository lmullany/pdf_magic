# PDF Magic

A small PHP app for uploading PDF files, rearranging or removing individual pages, and downloading the result. qpdf validates the uploaded documents and creates the merged PDF.

## Requirements

- PHP 8.1 or later with `fileinfo` and `proc_open` enabled
- qpdf available on `PATH` (or set `QPDF_BINARY` to its executable path)

Each PDF can be up to 25 MB, and up to 10 PDFs can be added to one merge. The PHP server's `upload_max_filesize` and `post_max_size` settings must allow the intended upload size; `post_max_size` should account for multiple files.

## Run locally

```sh
./scripts/serve.sh
```

Open <http://localhost:8000>. To choose a different host and port, pass them to the script, for example `./scripts/serve.sh 0.0.0.0 8080`.

Select one or more PDFs, then drag pages into the desired order or use the arrow buttons. Remove individual pages or entire PDFs before merging. The generated file downloads as `merged.pdf`.

Uploaded documents are stored in a private, per-session directory under the system temporary directory. Use **Clear all PDFs** to remove them when finished. Do not expose this development server directly to the public internet.

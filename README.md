Markdown to ePub Utility
========================

`md2epub` is a PHP script that converts a directory of markdown documents (`*.md`) and other resources to an electronic book compatible with the ePub 2.0.1 standard.

The instruction to compile the ebook must be entered in the `book.json` file.

The Markdown parser library is [Michel Fortin's PHP Markdown Extra](http://michelf.ca/projects/php-markdown/extra/).

The template parser library is [RainTPL](http://www.raintpl.com).

## Installation

Copy the application directory in a shared path (eg `/usr/local`) and create a link to `bin/md2epub`.

## Usage

From the command line type:

    md2epub /source/ebook/directory/ /dest/book.epub


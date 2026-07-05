<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

/**
 * How the content of a new page is supplied on the create form. Only a form
 * concern (the stored page keeps PageType), but enum-backed so the form,
 * validation, and views cannot drift apart on the literal values.
 */
enum PageCreationMode: string
{
    case Markdown = 'markdown';
    case HtmlPaste = 'html_paste';
    case HtmlUpload = 'html_upload';
}

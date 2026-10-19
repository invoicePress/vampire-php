<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Xpath\Parser;

enum TokenKind
{
    case Name;          // NCName or QName (prefix:local), includes keywords - Parser decides meaning by position
    case Variable;       // $name
    case String;         // 'x' or "x"
    case Number;         // 123 or 123.45 or .45
    case Slash;           // /
    case SlashSlash;      // //
    case DotDot;          // ..
    case Dot;             // .
    case At;              // @
    case DoubleColon;     // ::
    case ColonEquals;     // := ("let" only - zero occurrences in the real corpus, see docs/notes/xpath-whitelist.md)
    case LParen;          // (
    case RParen;          // )
    case LBracket;        // [
    case RBracket;        // ]
    case Comma;           // ,
    case Pipe;            // |
    case Star;             // *
    case Plus;             // +
    case Minus;             // -
    case Eq;                // =
    case Ne;                // !=
    case Lt;                // <
    case Le;                // <=
    case Gt;                 // >
    case Ge;                 // >=
    case Eof;
}

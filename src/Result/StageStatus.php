<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Result;

enum StageStatus
{
    case NotRun;
    case Passed;
    /** Stage ran and found at least one Fatal-flag violation. */
    case Failed;
    /** Stage was configured to run but was skipped (e.g. XsdStage `continue` mode after a prior fatal stage). */
    case Skipped;
}

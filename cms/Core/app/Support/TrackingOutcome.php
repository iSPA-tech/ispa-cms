<?php

namespace Cms\Core\Support;

enum TrackingOutcome: string
{
    case Tracked = 'tracked';           // new row created, or an existing not-yet-synced row's fingerprint was updated
    case Unchanged = 'unchanged';        // existing row, fingerprint identical — only reachable via the discovery fast-path
    case MissingSource = 'missing_source'; // the local file doesn't exist
    case Failed = 'failed';              // a tracking WRITE itself failed (e.g. DB error) — distinct from MissingSource, which means the *file* is the problem, not the write
    case Quarantined = 'quarantined';    // content changed at an already-synced path — held for manual review, see Decision 6 of the R2 migration plan
}

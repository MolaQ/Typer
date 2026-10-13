<?php

namespace App\Enums;

/**
 * Stan wniosku o zmianę nazwy zespołu (team_name_change_requests.status).
 */
enum TeamNameRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}

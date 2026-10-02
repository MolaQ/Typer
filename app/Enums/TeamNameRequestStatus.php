<?php

namespace App\Enums;

enum TeamNameRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
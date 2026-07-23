<?php

namespace App\Enums;

enum LibraryRole: string
{
    case Admin = 'admin';
    case Librarian = 'librarian';
    case Member = 'member';
}

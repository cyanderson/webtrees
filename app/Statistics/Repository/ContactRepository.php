<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2025 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Fisharebest\Webtrees\Statistics\Repository;

use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\User;
use Psr\Http\Message\ServerRequestInterface;

class ContactRepository
{
    private Tree $tree;

    private UserService $user_service;

    public function __construct(Tree $tree, UserService $user_service)
    {
        $this->tree         = $tree;
        $this->user_service = $user_service;
    }

    public function contactWebmaster(): string
    {
        $user_id = (int) $this->tree->getPreference('WEBMASTER_USER_ID');
        $user    = $this->user_service->find($user_id);

        if ($user instanceof User) {
            $request = Registry::container()->get(ServerRequestInterface::class);

            return $this->user_service->contactLink($user, $request);
        }

        return '';
    }

    public function contactGedcom(): string
    {
        $user_id = (int) $this->tree->getPreference('CONTACT_USER_ID');
        $user    = $this->user_service->find($user_id);

        if ($user instanceof User) {
            $request = Registry::container()->get(ServerRequestInterface::class);

            return $this->user_service->contactLink($user, $request);
        }

        return '';
    }
}

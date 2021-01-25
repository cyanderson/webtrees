<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2021 webtrees development team
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

namespace Fisharebest\Webtrees\Module;

use Aura\Router\RouterContainer;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Place;
use Fisharebest\Webtrees\PlaceLocation;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Services\SearchService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Statistics;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Webtrees;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function array_chunk;
use function array_pop;
use function array_reverse;
use function assert;
use function ceil;
use function count;
use function is_file;
use function redirect;
use function route;
use function view;

/**
 * Class IndividualListModule
 */
class PlaceHierarchyListModule extends AbstractModule implements ModuleListInterface, RequestHandlerInterface
{
    use ModuleListTrait;

    protected const ROUTE_URL = '/tree/{tree}/place-list';

    /** @var int The default access level for this module.  It can be changed in the control panel. */
    protected $access_level = Auth::PRIV_USER;

    /** @var SearchService */
    private $search_service;

    /**
     * PlaceHierarchy constructor.
     *
     * @param SearchService $search_service
     */
    public function __construct(SearchService $search_service)
    {
        $this->search_service = $search_service;
    }

    /**
     * Initialization.
     *
     * @return void
     */
    public function boot(): void
    {
        $router_container = app(RouterContainer::class);
        assert($router_container instanceof RouterContainer);

        $router_container->getMap()
            ->get(static::class, static::ROUTE_URL, $this);
    }

    /**
     * How should this module be identified in the control panel, etc.?
     *
     * @return string
     */
    public function title(): string
    {
        /* I18N: Name of a module/list */
        return I18N::translate('Place hierarchy');
    }

    /**
     * A sentence describing what this module does.
     *
     * @return string
     */
    public function description(): string
    {
        /* I18N: Description of the “Place hierarchy” module */
        return I18N::translate('The place hierarchy.');
    }

    /**
     * CSS class for the URL.
     *
     * @return string
     */
    public function listMenuClass(): string
    {
        return 'menu-list-plac';
    }

    /**
     * @param Tree    $tree
     * @param mixed[] $parameters
     *
     * @return string
     */
    public function listUrl(Tree $tree, array $parameters = []): string
    {
        $parameters['tree'] = $tree->name();

        return route(static::class, $parameters);
    }

    /**
     * @return string[]
     */
    public function listUrlAttributes(): array
    {
        return [];
    }

    /**
     * @param Tree $tree
     *
     * @return bool
     */
    public function listIsEmpty(Tree $tree): bool
    {
        return !DB::table('places')
            ->where('p_file', '=', $tree->id())
            ->exists();
    }

    /**
     * Handle URLs generated by older versions of webtrees
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getListAction(ServerRequestInterface $request): ResponseInterface
    {
        return redirect($this->listUrl($request->getAttribute('tree'), $request->getQueryParams()));
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $request->getAttribute('tree');
        assert($tree instanceof Tree);

        $user = $request->getAttribute('user');
        assert($user instanceof UserInterface);

        Auth::checkComponentAccess($this, ModuleListInterface::class, $tree, $user);

        $action2  = $request->getQueryParams()['action2'] ?? 'hierarchy';
        $place_id = (int) ($request->getQueryParams()['place_id'] ?? 0);
        $place    = Place::find($place_id, $tree);

        // Request for a non-existent place?
        if ($place_id !== $place->id()) {
            return redirect($place->url());
        }

        $content = '';
        $showmap = Site::getPreference('map-provider') !== '';
        $data    = null;

        if ($showmap) {
            $content .= view('modules/place-hierarchy/map', [
                'data'     => $this->mapData($tree, $place),
                'provider' => [
                    'url'    => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                    'options' => [
                        'attribution' => '<a href="https://www.openstreetmap.org/copyright">&copy; OpenStreetMap</a> contributors',
                        'max_zoom'    => 19
                    ]
                ]
            ]);
        }

        switch ($action2) {
            case 'list':
            default:
                $alt_link = I18N::translate('Show place hierarchy');
                $alt_url  = $this->listUrl($tree, ['action2' => 'hierarchy', 'place_id' => $place_id]);
                $content .= view('modules/place-hierarchy/list', ['columns' => $this->getList($tree)]);
                break;
            case 'hierarchy':
            case 'hierarchy-e':
                $alt_link = I18N::translate('Show all places in a list');
                $alt_url  = $this->listUrl($tree, ['action2' => 'list', 'place_id' => 0]);
                $data     = $this->getHierarchy($place);
                $content .= (null === $data || $showmap) ? '' : view('place-hierarchy', $data);
                if (null === $data || $action2 === 'hierarchy-e') {
                    $content .= view('modules/place-hierarchy/events', [
                        'indilist' => $this->search_service->searchIndividualsInPlace($place),
                        'famlist'  => $this->search_service->searchFamiliesInPlace($place),
                        'tree'     => $place->tree(),
                    ]);
                }
        }

        if ($data !== null && $action2 !== 'hierarchy-e' && $place->gedcomName() !== '') {
            $events_link = $this->listUrl($tree, ['action2' => 'hierarchy-e', 'place_id' => $place_id]);
        } else {
            $events_link = '';
        }

        $breadcrumbs = $this->breadcrumbs($place);

        return $this->viewResponse('modules/place-hierarchy/page', [
            'alt_link'    => $alt_link,
            'alt_url'     => $alt_url,
            'breadcrumbs' => $breadcrumbs['breadcrumbs'],
            'content'     => $content,
            'current'     => $breadcrumbs['current'],
            'events_link' => $events_link,
            'place'       => $place,
            'title'       => I18N::translate('Place hierarchy'),
            'tree'        => $tree,
            'world_url'   => $this->listUrl($tree)
        ]);
    }

    /**
     * @param Tree $tree
     *
     * @return array<array<Place>>
     */
    private function getList(Tree $tree): array
    {
        $places = $this->search_service->searchPlaces($tree, '')
            ->sort(static function (Place $x, Place $y): int {
                return $x->gedcomName() <=> $y->gedcomName();
            })
            ->all();

        $count = count($places);

        if ($places === []) {
            return [];
        }

        $columns = $count > 20 ? 3 : 2;

        return array_chunk($places, (int) ceil($count / $columns));
    }


    /**
     * @param Place $place
     *
     * @return array{'tree':Tree,'col_class':string,'columns':array<array<Place>>,'place':Place}|null
     */
    private function getHierarchy(Place $place): ?array
    {
        $child_places = $place->getChildPlaces();
        $numfound     = count($child_places);

        if ($numfound > 0) {
            $divisor = $numfound > 20 ? 3 : 2;

            return [
                'tree'      => $place->tree(),
                'col_class' => 'w-' . ($divisor === 2 ? '25' : '50'),
                'columns'   => array_chunk($child_places, (int) ceil($numfound / $divisor)),
                'place'     => $place,
            ];
        }

        return null;
    }

    /**
     * @param Place $place
     *
     * @return array{'breadcrumbs':array<Place>,'current':Place|null}
     */
    private function breadcrumbs(Place $place): array
    {
        $breadcrumbs = [];
        if ($place->gedcomName() !== '') {
            $breadcrumbs[] = $place;
            $parent_place  = $place->parent();
            while ($parent_place->gedcomName() !== '') {
                $breadcrumbs[] = $parent_place;
                $parent_place  = $parent_place->parent();
            }
            $breadcrumbs = array_reverse($breadcrumbs);
            $current     = array_pop($breadcrumbs);
        } else {
            $current = null;
        }

        return [
            'breadcrumbs' => $breadcrumbs,
            'current'     => $current,
        ];
    }

    /**
     * @param Tree  $tree
     * @param Place $placeObj
     *
     * @return array<mixed>
     */
    protected function mapData(Tree $tree, Place $placeObj): array
    {
        $places    = $placeObj->getChildPlaces();
        $features  = [];
        $sidebar   = '';
        $show_link = true;

        if ($places === []) {
            $places[] = $placeObj;
            $show_link = false;
        }

        foreach ($places as $id => $place) {
            $location = new PlaceLocation($place->gedcomName());

            if ($location->latitude() === null || $location->longitude() === null) {
                $sidebar_class = 'unmapped';
            } else {
                $sidebar_class = 'mapped';
                $features[]    = [
                    'type'       => 'Feature',
                    'id'         => $id,
                    'geometry'   => [
                        'type'        => 'Point',
                        'coordinates' => [$location->longitude(), $location->latitude()],
                    ],
                    'properties' => [
                        'tooltip' => $place->gedcomName(),
                        'popup'   => view('modules/place-hierarchy/popup', [
                            'showlink'  => $show_link,
                            'place'     => $place,
                            'latitude'  => $location->latitude(),
                            'longitude' => $location->longitude(),
                        ]),
                    ],
                ];
            }

            $statistics = new Statistics(app(ModuleService::class), $tree, app(UserService::class));

            //Stats
            $placeStats = [];
            foreach (['INDI', 'FAM'] as $type) {
                $tmp               = $statistics->statsPlaces($type, '', $place->id());
                $placeStats[$type] = $tmp === [] ? 0 : $tmp[0]->tot;
            }
            $sidebar .= view('modules/place-hierarchy/sidebar', [
                'showlink'      => $show_link,
                'id'            => $id,
                'place'         => $place,
                'sidebar_class' => $sidebar_class,
                'stats'         => $placeStats,
            ]);
        }

        return [
            'bounds'  => (new PlaceLocation($placeObj->gedcomName()))->boundingRectangle(),
            'sidebar' => $sidebar,
            'markers' => [
                'type'     => 'FeatureCollection',
                'features' => $features,
            ]
        ];
    }
}

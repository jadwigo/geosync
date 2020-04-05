<?php

namespace Bolt\Extension\Jadwigo\GeoSync;

use Bolt\Extension\SimpleExtension;
use Bolt\Menu\MenuEntry;
use Silex\Application;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use GuzzleHttp\Client;

/**
 * GeoSync extension class.
 *
 * @autor Lodewijk Evers <jadwigo@gmail.com>
 */
class GeoSyncExtension extends SimpleExtension
{
    //var $app;
    //var $config;

    /**
     * GeoSyncExtension constructor.
     */
    public function __construct()
    {
        //$this->app = $this->getContainer();
        //$this->config = $this->getConfig();
    }

    /**
     * @param ControllerCollection $collection
     */
    protected function registerBackendRoutes(ControllerCollection $collection)
    {
        // MATCH requests
        $collection->match('/extend/geosync', [$this, 'callbackGeoSyncAdmin']);

    }

    /**
     * {@inheritdoc}
     */
    protected function registerTwigFunctions()
    {
        return [
            'geosynctotals'    => 'totalsTwig',
        ];
    }

    /**
     * @return string
     */
    function totalsTwig() {
        $result = $this->getTotals();
        return $result;
    }

    protected function getTotals() {
        $number_of_addresses = 0;
        $number_of_verified = 0;
        $number_of_unverified = 0;
        $config = $this->getConfig();
        $app = $this->getContainer();

        $sql = "SELECT count(*) as total FROM bolt_addresses";
        $stmt = $app['db']->prepare($sql);
        $stmt->execute();
        $number_of_addresses = $stmt->fetchAll();

        $sql = "SELECT count(*) as verified FROM bolt_addresses WHERE accuracy > 0";
        $stmt = $app['db']->prepare($sql);
        $stmt->execute();
        $number_of_verified = $stmt->fetchAll();

        $sql = "SELECT count(*) as unverified FROM bolt_addresses WHERE accuracy < 1";
        $stmt = $app['db']->prepare($sql);
        $stmt->execute();
        $number_of_unverified = $stmt->fetchAll();

        //dump(current($number_of_addresses)['total'], current($number_of_verified), current($number_of_unverified));
        // Do something here with your repository object …
        //$qb = $app['storage']->createQueryBuilder();

        return sprintf("All: %d Verified: %d Unverified %d",
            current($number_of_addresses)['total'],
            current($number_of_verified)['verified'],
            current($number_of_unverified)['unverified']
        );
    }

    /**
     * @param Application $app
     * @param Request $request
     * @return Response
     */
    function callbackGeoSyncAdmin(Application $app, Request $request)
    {
        $config = $this->getConfig();

        $template_vars = [
            'messages' => ['hello'],
            'title' => 'GeoSync',
            'body' => 'no body',
            'location' => 'no location',
            'address' => 'no address',
            'nearby' => 'no nearby',
            'details' => 'no details',
            'table' => null
        ];

        $repo = $app['storage']->getRepository($config['synctype']['contenttype']);

        $template_vars['table']['rows'][] = [
            'class' => 'header',
            'cells' => [
                ['value' => 'id'],
                ['value' => 'title'],
                ['value' => 'link'],
                ['value' => 'updated']
            ]
        ];

        if($config['geosync']['enabled']) {
            $entries = $this->geosyncGetContent();
            foreach ($entries as $entry) {
                $entry = $repo->find($entry['id']);
                $entryaddress = join(', ', [$entry['adres'], $entry['postcode'], $entry['plaats']]);
                // dump($entry);
                $entry = $this->geosyncAddressDetails($entryaddress, $entry);
                $entry->datechanged = date('Y-m-d H:i:s', time());
                $entry->accuracy = 1;
                $result = $repo->save($entry);
                $template_vars['table']['rows'][] = [
                    'class' => '',
                    'cells' => [
                        ['value' => $entry->id],
                        ['value' => '<a href="/bolt/editcontent/addresses/' . $entry->id . '">' . $entry->title . '</a>'],
                        ['value' => $entry->link()],
                        ['value' => $result]
                    ]
                ];
            }

        }

        //$searchaddress = 'DOORNLAAN 1 b 6717 BN EDE GLD';
        //$searchaddress = 'cartesiusstraat 9, 2562 SB den haag';
        //$template_vars['details'] = $this->geosyncAddressDetails($searchaddress);

        $body = $this->renderTemplate($config['admintemplate'], $template_vars);

        return new Response($body, Response::HTTP_OK);
    }


    /**
     * @param $array
     * @return array
     */
    protected function flattenAddress($array)
    {
        //dump($array);
        $flattened = [];
        foreach($array as $key => $element) {
            switch($element['types'][0]) {
                case 'street_number':
                    $flattened['nummer'] = $element['long_name'];
                    break;
                case 'route':
                    $flattened['adres'] = $element['long_name'];
                    break;
                case 'locality':
                    $flattened['plaats'] = $element['long_name'];
                    break;
                case 'postal_code':
                    $flattened['postcode'] = $element['long_name'];
                    break;
                case 'administrative_area_level_2':
                    $flattened['gemeente'] = $element['long_name'];
                    break;
                case 'administrative_area_level_1':
                    $flattened['provincie'] = $element['long_name'];
                    break;
                case 'country':
                    $flattened['land'] = $element['long_name'];
                    break;
            }
        }

        if(!empty($flattened['nummer'])) {
            $flattened['adres'] .= ' ' . $flattened['nummer'];
        }
        return $flattened;
    }

    /**
     * @return mixed
     */
    protected function geosyncGetContent()
    {
        $config = $this->getConfig();
        $app = $this->getContainer();

        // Getting a repository via alias.
        $repo = $app['storage']->getRepository($config['synctype']['contenttype']);

        $qb = $repo->createQueryBuilder();
        $qb->where('accuracy < 1')
            ->orderBy('title', 'ASC')
            ->setMaxResults(10);

        $entries = $qb->execute()->fetchAll();

        // Do something here with your repository object …
        //$qb = $app['storage']->createQueryBuilder();

        return $entries;
    }

    /**
     * @param $searchaddress
     * @return bool|mixed|string
     */
    protected function geosyncAddressDetails($searchaddress, $entry)
    {
        $config = $this->getConfig();
        $location = $this->geosyncGeocode($searchaddress);

        if($location['status']!='OK') {
            $entry->locationid = 'error';
            $entry->locationdetails = json_encode($location);
            return $entry;
        };

        $addressses = $location['results'];
        $address = reset($addressses);

        if($address && $address['geometry']['location']) {
            //dump($address);
            $entry->locationid = $address['place_id'];
            $entry->locationdetails = json_encode($address);
            $entry->latitude = $address['geometry']['location']['lat'];
            $entry->longitude = $address['geometry']['location']['lng'];

            $coords = $address['geometry']['location'];
            if(!isset($type)) {
                $type = 'pharmacy';
            }
            // $nearbytypes =  $config['nearby']['types'];
            if($config['nearby']['enabled']) {
                $nearby = $this->geosyncNearby($coords, $type);
            } else {
                $nearby = ['no address - disabled'];
            }

        } else {
            $nearby = ['no address coords found'];
        }

        if($nearby['status']!='OK') {
            return $entry;
        };
        if(!array_key_exists('results', $nearby) || !is_array($nearby['results'])) {
            return $entry;
        }

        $nearaddressses = $nearby['results'];
        $nearaddress = reset($nearaddressses);

        if($nearaddress['place_id']) {
            if($config['details']['enabled']) {
                $details = $this->geosyncDetails($nearaddress['place_id']);
            } else {
                $config = ['no details - disabled'];
            }

            if($details['status']!='OK') {
                $entry->businessid = 'error';
                $entry->businessdetails = json_encode($details);
                return $entry;
            };
            $entry->businessid = $nearaddress['place_id'];

            $bizresult = $details['result'];
            $bpairs = [
                'website' => 'website',
                'url' => 'googlemapslink',
                'international_phone_number' => 'telefoon',
                'formatted_phone_number' => 'telefoon',
            ];
            foreach($bpairs as $bkey => $bvalue) {
                if(array_key_exists($bkey, $bizresult)) {
                    $entry->$bvalue = $bizresult[$bkey];
                }
            }

            $entry->businessdetails = json_encode($details);
        } else {
            $details = ['no address place id found'];
        }

        if($address['address_components']) {
            $cleanedaddress = $this->flattenAddress($address['address_components']);
            //dump($cleanedaddress);
            if (!empty($cleanedaddress)) {
                foreach ($cleanedaddress as $label => $value) {
                    //print "hoi $label => $value / ". $entry->$label . " <br>";
                    if (empty($entry->$label)) {
                        //print 'YO <br>';
                        $entry->$label = $value;
                    }
                }
            }
        }

        //dump($entry);
        return $entry;
    }

    /**
     * function to geocode address, it will return false if unable to geocode address
     * @param $address
     * @return bool|mixed
     */
    protected function geosyncGeocode($address){
        $config = $this->getConfig();
        $app = $this->getContainer();
        $cache = $app['cache'];
        $cacheKey = $app['slugify']->slugify('geosync:' . $address);
        $geolocation = false;

        if($config['use_cache']) {
            $geolocation = $cache->fetch($cacheKey);
        }

        if(!$config['addresslookup']['enabled']) {
            return false;
        }

        if($geolocation===false) {
            // url encode the address
            $address = urlencode($address);

            // google map geocode api url
            //$url = "http://medisch.xyz.test/test.php";
            $url = $config['addresslookup']['provider'];

            // Create a client with a base URI
            $client = new Client(['base_uri' => $url]);

            $query = [
                'query' => [
                    'address' => $address,
                    'language' => 'nl',
                    'region' => 'nl',
                    'key' => $config['authkey']
                ]
            ];

            $response = $client->request('GET', $url, $query);
            $geolocation = json_decode($response->getBody(), true);
            $cache->save($cacheKey, $geolocation, $config['cache_time']);
            $app['session']->getFlashBag()->set('success', 'loaded geolocation for ' . $address . ' from remote provider');
        } else {
            //$app['messages'];
            $app['session']->getFlashBag()->set('success', 'loaded geolocation for ' . $address . ' from cache');
        }

        return $geolocation;
    }

    /**
     * Get the google location id for a bysiness of type X
     * https://developers.google.com/places/web-service/search
     *
     * @param $coords
     * @return bool|mixed
     */
    protected function geosyncNearby($coords, $type = 'pharmacy') {
        $coords = number_format($coords['lat'], 6, '.', '')
            . ',' . number_format($coords['lng'], 6, '.', '');
        $config = $this->getConfig();
        $app = $this->getContainer();
        $cache = $app['cache'];
        $cacheKey = $app['slugify']->slugify('geonearby:' . $coords);
        $geonearby = false;

        if($config['use_cache']) {
            $geonearby = $cache->fetch($cacheKey);
        }

        if($geonearby===false) {
            // google map geocode api url
            //$url = "http://medisch.xyz.test/test.php";
            $url = $config['nearby']['provider'];

            // Create a client with a base URI
            $client = new Client(['base_uri' => $url]);

            $query = [
                'query' => [
                    'location' => $coords,
                    'type' => $type,
                    'radius' => 500,
                    'key' => $config['authkey']
                ]
            ];

            $response = $client->request('GET', $url, $query);
            $geonearby = json_decode($response->getBody(), true);
            $cache->save($cacheKey, $geonearby, $config['cache_time']);
            $app['session']->getFlashBag()->set('success', 'loaded geo nearby for ' . $coords . ' from remote provider');
        } else {
            //$app['messages'];
            $app['session']->getFlashBag()->set('success', 'loaded geo nearby for ' . $coords . ' from cache');
        }

        return $geonearby;
    }


    /**
     * https://developers.google.com/places/web-service/details
     *
     * @param $place_id
     * @return bool|mixed
     */
    protected function geosyncDetails($place_id) {
        $config = $this->getConfig();
        $app = $this->getContainer();
        $cache = $app['cache'];
        $cacheKey = $app['slugify']->slugify('geodetails:' . $place_id);
        $geodetails = false;

        if($config['use_cache']) {
            $geodetails = $cache->fetch($cacheKey);
        }

        if($geodetails===false) {
            // google map geocode api url
            //$url = "http://medisch.xyz.test/test.php";
            $url = $config['details']['provider'];

            // Create a client with a base URI
            $client = new Client(['base_uri' => $url]);

            $query = [
                'query' => [
                    'placeid' => $place_id,
                    'key' => $config['authkey']
                ]
            ];

            $response = $client->request('GET', $url, $query);
            $geodetails = json_decode($response->getBody(), true);
            $cache->save($cacheKey, $geodetails, $config['cache_time']);
            $app['session']->getFlashBag()->set('success', 'loaded geo details for ' . $place_id . ' from remote provider');
        } else {
            //$app['messages'];
            $app['session']->getFlashBag()->set('success', 'loaded geo details for ' . $place_id . ' from cache');
        }

        return $geodetails;
    }

    /**
     * @return array
     */
    protected function registerMenuEntries()
    {
        $menu = new MenuEntry('geosync-menu', 'geosync');
        $menu->setLabel('GeoSync')
            ->setIcon('fa:leaf')
            ->setPermission('settings')
        ;

        return [
            $menu,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultConfig()
    {
        $key = null;
        // set the global api key as default - if it exists
        $app = $this->getContainer();
        $key = $app['config']->get('general/google_api_key');
        return [
            'admintemplate' => 'geosyncadmin.twig',
            'geosync' => [
                'enabled' => true,
            ],
            'synctype' => [
                'contenttype' => 'pages',
            ],
            'addresslookup' => [
                'enabled' => true,
                'provider' => 'https://maps.googleapis.com/maps/api/geocode/json',
                'template' => '?address=Cartesiusstraat 9, 2562SB Den Haag&language=nl&region=nl&key=YOUR_API_KEY',
            ],
            'nearby' => [
                'enabled'=>true,
                'provider' => 'https://maps.googleapis.com/maps/api/place/nearbysearch/json',
                'types' => ['hospital', 'pharmacy', 'physiotherapist', 'dentist', 'doctor'],
                'template' => '?location=-33.8670522,151.1957362&radius=500&type=restaurant&keyword=cruise&key=YOUR_API_KEY',
            ],
            'details' => [
                'enabled' => true,
                'provider' => 'https://maps.googleapis.com/maps/api/place/details/json',
                'template' => '?placeid=ChIJN1t_tDeuEmsRUsoyG83frY4&key=YOUR_API_KEY',
            ],
            'authkey' => $key,
            'use_cache' => true,
            'cache_time' => (60*60*24*5)
        ];
    }
}


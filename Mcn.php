<?php

    namespace App\Helpers;

    use App\Models\Cities;
    use App\Models\GuestZipcode;
    use App\Models\NeighbourZipcodes;
    use App\Models\PostMedia;
    use App\Models\Posts;
    use GuzzleHttp\Client;
    use GuzzleHttp\Exception\GuzzleException;
    use Illuminate\Filesystem\Filesystem as File;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\Session;
    use Illuminate\Support\Facades\Cache;
    use Carbon\Carbon;
    use Illuminate\Support\Str;

    /*
     * Mcn Helper Class
     * */

    class Mcn
    {

        const neighbourZipcodesCacheKeyPrefix = 'neighbour_zipcodes_';


        /**
         * @param $lat
         * @param $lng
         * @param $checkWithIp
         * @return bool|mixed|null
         * @throws GuzzleException
         */
        public static function getLocationDetailsFromLatLong($lat, $lng, $checkWithIp = false)
        {
            $client = new Client();
            $zip_code = null;
            if (env('TEST_MODE')) {
                return env('TEST_ZIPCODE');
            }
            /*$response = $client->get("https://maps.googleapis.com/maps/api/geocode/json?latlng={$lat},{$lng}&key=".env('GOOGLE_API_KEY'));
            $data = json_decode($response->getBody(), true);
            foreach ($data['results'][0]['address_components'] as $component) {
                if (in_array('postal_code', $component['types'])) {
                    $zip_code = $component['long_name'];
                    break;
                }
            }*/

            $response = $client->get("https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lng}");
            $data = json_decode($response->getBody(), true);
            if (isset($data['address']['postcode']) && !empty($data['address']['postcode'])) {
                $zip_code = $data['address']['postcode'];
            }
            if ($checkWithIp && is_null($zip_code)) {
                return self::getLocationDetailsFromIp();
            }
            return $zip_code;
        }

        /**
         * @param $ip
         * @return bool|null
         * @throws GuzzleException
         */
        public static function getLocationDetailsFromIp($ip = null)
        {
            $ip = $ip ? $ip : request()->ip();
            $ip = ($ip == '127.0.0.1') ? '121.52.159.174' : $ip;
            if (env('TEST_MODE')) {
                return env('TEST_ZIPCODE');
            }
            $client = new Client();
            $response = $client->get("http://api.ipstack.com/{$ip}?access_key=" . env('IPSTACK_KEY'));
            $data = json_decode($response->getBody(), true);
            $zipCode = !empty($data['zip']) ? $data['zip'] : null;
            return $zipCode;
        }

        /**
         * @param $zip_code
         * @return bool|void
         */
        public static function setUserLocationZipcode($zip_code = null, $forceUpdate = false)
        {
            if (empty($zip_code)) {
                return false;
            }
            if (!Auth::check()) {
                $guestZipcode = GuestZipcode::updateOrCreate([
                    'session_id' => self::getBrowserSessionId()
                ], [
                    'zipcode' => $zip_code,
                ]);
                request()->session()->put('zipcode', $zip_code);
                return true;
            } else {
                if ($forceUpdate || empty(auth()->user()->zipcode)) {
                    auth()->user()->update(['zipcode' => $zip_code]);
                    request()->session()->put('zipcode', $zip_code);
                }
                return true;
            }
        }

        /**
         * @return void
         */
        public static function getUserLocationZipcode(): string
        {
            $zipcode = request()->session()->get('zipcode');
            if ($zipcode) {
                return $zipcode;
            }
            if (!Auth::check()) {
                $guestZipcode = GuestZipcode::findOne(['session_id' => self::getBrowserSessionId()]);
                $zipcode = $guestZipcode->zipcode;
            } else {
                $zipcode = auth()->user()->zipcode;
            }
            request()->session()->put('zipcode', $zipcode);
            return $zipcode;
        }

        /**
         * @return string
         */
        public static function getBrowserSessionId()
        {
            return Session::getId();
        }

        /**
         * @param $sessionId
         * @return void
         */
        public static function setBrowserSessionId($sessionId)
        {
        }

        public static function getNeighbourZipcodes($includeSelfZipcode = true, $zipCode = null): array
        {
            $zipCode = ($zipCode) ? $zipCode : self::getUserLocationZipcode();
            if (Cache::has(self::neighbourZipcodesCacheKeyPrefix . $zipCode)) {
                $neighbourZipcodes = explode(',', Cache::get(self::neighbourZipcodesCacheKeyPrefix . $zipCode, ''));
            } else {
                $neighbourZipcodes = NeighbourZipcodes::where([
                    'zipcode' => $zipCode,
                    'distance_miles' => env('SEARCH_RANGE', 5)
                ])->pluck('neighbour_zip_code')->toArray();
                Cache::add(self::neighbourZipcodesCacheKeyPrefix . $zipCode, implode(',', $neighbourZipcodes));
            }
            if ($includeSelfZipcode) {
                $neighbourZipcodes[] = $zipCode;
            }
            return array_unique($neighbourZipcodes);
        }

        public static function formatDate($date, $inputFormat = 'Y-m-d H:i:s', $format = 'M d, Y')
        {
            return Carbon::createFromFormat($inputFormat, $date)
                ->format($format);
        }

        public static function estimateReadingTime($text, $wpm = 200)
        {
            $totalWords = str_word_count(strip_tags($text));
            $minutes = floor($totalWords / $wpm);
            $seconds = floor($totalWords % $wpm / ($wpm / 60));

            return ($seconds > 0) ? sprintf('%s minutes %s seconds', $minutes, $seconds) : sprintf('%s minutes', $minutes);
        }

        public static function getOriginsList($keyword = '')
        {
            $originsListQuery = Cities::where('is_default', 1)
                ->selectRaw('CONCAT(name, ", ",state, " (", zipcode, ")") as origin_name, name, zipcode, state')
                ->orderByRaw('origin_name ASC');
            if (!empty($keyword)) {
                $originsListQuery->whereRaw('CONCAT(name, ", ",state, " (", zipcode, ")") LIKE?', "%$keyword%");
            }
            $originsList = $originsListQuery->get()->toArray();
            return $originsList;
        }


        /**
         * @param Posts $post
         * @param array $files
         * @param $type
         * @return bool
         */
        public static function uploadPostMedia(Posts $post, array $files, $type)
        {
            try {
                $isFeatured = 0;
                if ($type == 'featured_image') {
                    $isFeatured = 1;
                    $type = 'image';
                }
                $directoryPath = self::getUploadDirectoryPath('post');
                self::createDirectoryIfNotExists($directoryPath);
                foreach ($files as $index => $file) {
                    $name = time() . rand(
                            1,
                            99999999
                        ) . '_' . $index . '_' . $post->id . "." . $file->getClientOriginalExtension();
                    if ($file->move($directoryPath, $name)) {
                        $postMedia = new PostMedia([
                            'entity_type' => 'post',
                            'entity_id' => $post->id,
                            'path' => $name,
                            'type' => $type,
                            'is_featured' => $isFeatured,
                            'status' => 1,
                        ]);
                        $postMedia->save();
                    }
                }
                return true;
            } catch (\Exception $e) {
                echo "<pre>";
                print_r($e->getMessage());
                echo "</pre>";
                die('Call');
                return false;
            }
        }

        /**
         * @param $path
         * @return void
         */
        public static function createDirectoryIfNotExists($path)
        {
            if (!is_dir($path)) {
                File::makeDirectory($path, 0777, true);
            }
        }

        public static function getUploadDirectoryPath($type)
        {
            switch ($type) {
                case 'post':
                    return public_path('assets/images/posts');
                    break;
                case 'avatar':
                    return public_path('assets/images/avatars');
                    break;
                case 'ads':
                    return public_path('assets/images/ads');
                    break;
            }
        }

        public static function getFormattedOriginName($origin, $data = 'fullNameWithAddress')
        {
            switch ($data) {
                case 'fullNameWithAddress':
                    return ucwords(strtolower($origin['name'])) . ', ' . $origin['state'] . ' ' . $origin['zipcode'];
                    break;
                case 'name';
                    return ucwords(strtolower($origin['name']));
                    break;
            }
        }

        public static function clipText($longText, $limit = 100, $end = '...'){
            return Str::limit($longText, $limit, $end);
        }

        public static function getMediaPath($type){
            switch ($type){
                case 'post':
                    return public_path('assets/images/posts');
                    break;
                case 'avatar':
                    return public_path('assets/images/avatars');
                    break;
                case 'ads':
                    return public_path('assets/images/ads');
                    break;
            }
        }

        public static function deleteMediaForPost($media){
            $path = self::getMediaPath($media->entity_type);
            $fullPath = $path. '/'. $media->path;
            if(file_exists($fullPath)){
                @unlink($fullPath);
            }
        }


    }

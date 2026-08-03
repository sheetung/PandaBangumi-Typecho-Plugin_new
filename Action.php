<?php
/**
 * Action.php
 *
 * API 获取、更新数据，处理前端 AJAX 请求
 *
 * @author 熊猫小A
 */
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once 'simple_html_dom.php';

class BangumiAPI
{
    private static $debugInfo = array();

    public static function getDebugInfo()
    {
        return self::$debugInfo;
    }

    private static function debug($key, $value)
    {
        self::$debugInfo[$key] = $value;
    }
    /**
     * 使用 curl 代替 file_get_contents()
     *
     * @access public
     */
    public static function curlFileGetContents($_url)
    {
        $startedAt = microtime(true);
        $ch = curl_init($_url);

        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,

            // 部分服务器的 IPv6 出口不可用，会导致连接 api.bgm.tv 超时。
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,

            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,

            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            CURLOPT_REFERER => 'https://bgm.tv/',
            CURLOPT_USERAGENT =>
                'moontung.top PandaBangumi/2.5 ' .
                '(https://moontung.top)',

            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
            ),
        ));

        $content = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        self::$debugInfo['requests'][] = array(
            'url' => $_url,
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'response_length' => is_string($content) ? strlen($content) : 0,
            'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        );

        if ($content === false) {
            error_log(
                '[PandaBangumi] cURL error: ' .
                $curlError . ' | URL: ' . $_url
            );

            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log(
                '[PandaBangumi] HTTP ' .
                $httpCode . ' | URL: ' . $_url
            );

            return null;
        }

        return $content;
    }

    /**
     * 获取在看数据并格式化返回
     *
     * @return mixed
     */
    private static function __getCollectionRawData($ID)
    {
        // 配置项保存的是数字用户 ID，数字 ID 优先使用兼容性更好的旧接口。
        // 新 v0 接口要求 username，因此仅在旧接口失败且 ID 不是纯数字时回退。
        $apiUrl = 'https://api.bgm.tv/user/' . rawurlencode($ID) . '/collection?cat=playing';
        $data = self::curlFileGetContents($apiUrl);
        $legacyDecoded = is_string($data) ? json_decode($data, true) : null;
        if ((!is_string($data) || $data === '' || $data === 'null' || $legacyDecoded === array()) && !ctype_digit((string) $ID)) {
            $apiUrl = 'https://api.bgm.tv/v0/users/' . rawurlencode($ID) .
                '/collections?subject_type=2&type=3&limit=50&offset=0';
            $v0Data = self::curlFileGetContents($apiUrl);
            $decoded = is_string($v0Data) ? json_decode($v0Data, true) : null;
            if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
                $data = json_encode($decoded['data']);
            }
        }
        if (!is_string($data) || $data === '' || $data === 'null') {
            return array(); // 没有标记数据或请求失败
        }

        $data = json_decode($data, true);
        if (!is_array($data)) {
            error_log('[PandaBangumi] Invalid collection JSON: ' . json_last_error_msg());
            return array();
        }

        self::debug('collection_items_from_api', count($data));

        $weekdays = array('Mon.', 'Tue.', 'Wed.', 'Thu', 'Fri', 'Sat', 'Sun');
        $collections = array();
        foreach ($data as $item) {
            if (!isset($item['subject']) || !is_array($item['subject'])) {
                continue;
            }
            $subject = $item['subject'];
            $weekday = isset($subject['air_weekday']) ? (int) $subject['air_weekday'] : 0;
            $collect = array(
                'name' => isset($subject['name']) ? $subject['name'] : '',
                'name_cn' => isset($subject['name_cn']) ? $subject['name_cn'] : '',
                'url' => isset($subject['url']) ? $subject['url'] : '',
                'status' => isset($item['ep_status']) ? (int) $item['ep_status'] : 0,
                'count' => isset($subject['eps_count']) ? (int) $subject['eps_count'] : 0,
                'air_date' => isset($subject['air_date']) ? $subject['air_date'] : '',
                'air_weekday' => ($weekday >= 1 && $weekday <= 7) ? $weekdays[$weekday - 1] : '',
                'img' => isset($subject['images']['large']) ? str_replace('http://', 'https://', $subject['images']['large']) : '',
                'id' => isset($subject['id']) ? $subject['id'] : 0,
            );
            array_push($collections, $collect);
        }
        return $collections;
    }

    /**
     * @return array
     */
    private static function __getWatchedCollectionRawDataHelper($url)
    {
        $data = self::curlFileGetContents($url);
        if ($data == 'null') {
            return array(); // 没有标记数据
        }

        $data = json_decode($data, true)[0];

        $result = array();
        foreach ($data['collects'] as $collect) {
            // 只处理已看
            if ($collect['status']['id'] != 2) continue;

            foreach ($collect['list'] as $item) {
                array_push($result, array(
                    'name' => $item['subject']['name'],
                    'name_cn' => $item['subject']['name_cn'],
                    'url' => $item['subject']['url'],
                    'img' => str_replace('http://', 'https://', $item['subject']['images']['large']),
                    'id' => $item['subject']['id'],
                ));
            }
        }

        return $result;
    }

    /**
     * 检查缓存是否过期
     *
     * @access  private
     * @param   string    $FilePath           缓存路径
     * @param   int       $ValidTimeSpan      有效时间，Unix 时间戳，s
     * @return  mixed     正常数据: 未过期; 1:已过期; -1：无缓存或缓存无效
     */
    private static function __isCacheExpired($FilePath, $ValidTimeSpan)
    {
        if (!file_exists($FilePath)) {
            return -1;
        }

        $content = json_decode(file_get_contents($FilePath), true);
        if (!is_array($content) || !array_key_exists('time', $content) || $content['time'] < 1) {
            return -1;
        }

        if (time() - $content['time'] > $ValidTimeSpan) {
            return 1;
        }

        return $content;
    }

    private static function __parseFromDoc($doc) {
        $result = array();
        $bgmBase = 'https://bgm.tv';
        foreach ($doc->find('#browserItemList li.item') as $item) {
            $name_cn = $item->find('h3 a', 0)->text();
            $name = $name_cn;
            if ($item->find('h3 small', 0) != null)
                $name = $item->find('h3 small', 0)->text();

            $res = array(
                'name_cn' => $name_cn,
                'name' => $name,
                'url' => $bgmBase.$item->find('h3 a', 0)->href,
                'img' => str_replace('cover/s/', 'cover/l/',$item->find('img.cover', 0)->src),
                'id' => str_replace('item_', '', $item->id)
            );

            if (empty($res['img']))
                $res['img'] = str_replace('cover/s/', 'cover/l/', 
                    $item->find('img.cover', 0)->getAttribute('data-cfsrc'));

            array_push($result, $res);
        }
        return $result;
    }

    /**
     * 通过网页解析在看列表
     * 
     * @access public
     * @param  string $Type 获取类型：anime, real
     * @param  string $ID Bangumi ID
     * @return array
     */
    public static function __getWatchedCollectionRawDataByWebHelper($ID, $Type)
    {
        // 初始 URL
        $bgmBase = 'https://bgm.tv';
        $url = "https://bgm.tv/{$Type}/list/{$ID}/collect";
        $html = self::curlFileGetContents($url);
        if ($html == 'null') {
            return array(); // 没有标记数据
        }

        $doc = str_get_html($html);

        // 解析页面链接
        $urls = array();
        $pagerEls = $doc->find('#multipage a.p');
        foreach ($pagerEls as $pagerEl) {
            $urls[] = $bgmBase.$pagerEl->href;
        }
        $urls = array_unique($urls);

        $result = array();
        $Limit = Helper::options()->plugin('PandaBangumi')->Limit;
        
        // 保存第一页
        $result = array_merge($result, self::__parseFromDoc($doc));

        // 若不够
        while (count($result) < $Limit && count($urls)) {
            $url = array_shift($urls);
            $html = self::curlFileGetContents($url);
            if ($html == 'null') break;
            $doc = str_get_html($html);

            $result = array_merge($result, self::__parseFromDoc($doc));
        }

        return $result;
    }

    /**
     * 读取与更新本地已看缓存，格式化返回已看数据
     * 
     * @access public
     * @return string
     */
    public static function updateWatchedCacheAndReturn($ID, $PageSize, $From, $ValidTimeSpan)
    {
        $cache = self::__isCacheExpired(__DIR__ . '/json/watched.json', $ValidTimeSpan);

        // 缓存过期或缓存无效
        if ($cache == -1 || $cache == 1) {
            // 缓存无效，重新请求，数据写入

            $appId = 'bgm25a91b0a9bfd7a';

            $method = Helper::options()->plugin('PandaBangumi')->ParseMethod;

            $watchedAnime = array();
            $watchedReal = array();
            if ($method == 'webpage') {
                $watchedAnime = self::__getWatchedCollectionRawDataByWebHelper($ID, 'anime');
                $watchedReal = self::__getWatchedCollectionRawDataByWebHelper($ID, 'real');
            } else {
                $watchedAnime = self::__getWatchedCollectionRawDataHelper(
                    'https://api.bgm.tv/user/' . $ID . '/collections/anime?app_id=' . $appId . '&max_results=25'
                );
                $watchedReal = self::__getWatchedCollectionRawDataHelper(
                    'https://api.bgm.tv/user/' . $ID . '/collections/real?app_id=' . $appId . '&max_results=25'
                );
            }

            $cache = array('time' => time(), 'data' => array(
                        'anime' => $watchedAnime,
                        'real' => $watchedReal)
                    );
            // 若全空，很可能是请求失败，则下次强制刷新
            if (!count($watchedAnime) && !count($watchedReal)) {
                $cache['time'] = 1;
            }

            file_put_contents(__DIR__ . '/json/watched.json', json_encode($cache));
        }

        $cate = array_key_exists('cate', $_GET) ? $_GET['cate'] : 'anime';
        if (!array_key_exists($cate, $cache['data'])) 
            return json_encode(array());

        $data = $cache['data'][$cate];
        $total = count($data);

        if ($From < 0 || $From > $total - 1) {
            echo json_encode(array());
        } else {
            $end = min($From + $PageSize, $total);
            $out = array();
            for ($index = $From; $index < $end; $index++) {
                array_push($out, $data[$index]);
            }
            return json_encode($out);
        }
    }

    /**
     * 获取日历数据
     *
     * @return mixed
     */
    private static function __getCalendarRawData($ID, $filter, $hideFinished = false)
    {
        // 初始化日历数据结构
        $result = array();
        $weekdays = array('周一', '周二', '周三', '周四', '周五', '周六', '周日');
        
        // 为每一天创建一个空的条目数组
        for ($i = 0; $i < 7; $i++) {
            array_push($result, array(
                'weekday' => array(
                    'cn' => $weekdays[$i],
                    'ja' => '',
                    'en' => '',
                ),
                'items' => array()
            ));
        }

        // 获取用户的在看数据
        $watchingCache = self::__isCacheExpired(__DIR__ . '/json/watching.json', 86400);
        // 如果缓存不存在或已过期，先更新缓存
        if ($watchingCache == -1 || $watchingCache == 1) {
            $raw = self::__getCollectionRawData($ID);
            if (!is_array($raw)) {
                $raw = array();
            }
            $watchingCache = array(
                'time' => count($raw) > 0 ? time() : 1,
                'data' => $raw,
            );
            file_put_contents(
                __DIR__ . '/json/watching.json',
                json_encode($watchingCache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
        }
        
        if ($watchingCache != -1 && $watchingCache != 1) {
            foreach ($watchingCache['data'] as $item) {
                // 确保番剧有 air_weekday 字段
                if (isset($item['air_weekday']) && !empty($item['air_weekday'])) {
                    // 找到对应的星期几（注意：Bangumi API 中，周日是 7，这里需要转换为 0-6 的索引）
                    $weekdayIndex = 0;
                    switch (substr($item['air_weekday'], 0, 3)) {
                        case 'Mon':
                            $weekdayIndex = 0;
                            break;
                        case 'Tue':
                            $weekdayIndex = 1;
                            break;
                        case 'Wed':
                            $weekdayIndex = 2;
                            break;
                        case 'Thu':
                            $weekdayIndex = 3;
                            break;
                        case 'Fri':
                            $weekdayIndex = 4;
                            break;
                        case 'Sat':
                            $weekdayIndex = 5;
                            break;
                        case 'Sun':
                            $weekdayIndex = 6;
                            break;
                    }
                    
                    // 直接使用观看进度判断，避免日历生成时产生 N+1 个详情 API 请求。
                    $status = isset($item['status']) ? (int) $item['status'] : 0;
                    $count = isset($item['count']) ? (int) $item['count'] : 0;
                    if ($hideFinished && $count > 0 && $status >= $count) {
                        continue;
                    }
                    
                    // 创建番剧条目
                    $collect = array(
                        'name' => $item['name'],
                        'name_cn' => $item['name_cn'],
                        'url' => $item['url'],
                        'img' => $item['img'],
                        'id' => $item['id'],
                    );
                    
                    // 将番剧添加到对应的星期几
                    array_push($result[$weekdayIndex]['items'], $collect);
                }
            }
        }

        return $result;
    }

    /**
     * 读取与更新日历缓存，格式化返回数据
     *
     * @access public
     * @return string
     */
    public static function updateCalendarCacheAndReturn($ID, $ValidTimeSpan, $filter)
    {
        // 获取是否隐藏已完结番剧的设置
        $hideFinished = false;
        $bgmst = Helper::options()->plugin('PandaBangumi')->ShowFinished;
        
        if (!empty($bgmst) && in_array('hide', $bgmst)) {
            $hideFinished = true;
        }
        if (isset($_GET['hideFinished'])) {
            $hideFinished = in_array(
                strtolower((string) $_GET['hideFinished']),
                array('1', 'true', 'yes'),
                true
            );
        }

        self::debug('calendar', array(
            'user' => $ID,
            'filter' => $filter,
            'hide_finished' => $hideFinished,
            'cache_valid_seconds' => $ValidTimeSpan,
        ));
        
        $cacheFile = __DIR__ . '/json/calendar.json';
        if ($filter == 'watching') {
            $cacheFile = __DIR__ . '/json/calendar_watching.json';
        } elseif ($hideFinished) {
            $cacheFile = __DIR__ . '/json/calendar_no_finished.json';
        }
        
        $cache = self::__isCacheExpired($cacheFile, $ValidTimeSpan);
        $cacheState = $cache == -1 ? 'missing' : ($cache == 1 ? 'expired' : 'valid');

        if ($cache == -1 || $cache == 1) {
            $raw = self::__getCalendarRawData($ID, $filter, $hideFinished);
            if (count($raw) == 0) {
                $cache = array('time' => 1, 'data' => array());
            } else {
                $cache = array('time' => time(), 'data' => $raw);
            }
            file_put_contents($cacheFile, json_encode($cache));
        }

        self::debug('calendar_result', array(
            'cache_file' => basename($cacheFile),
            'cache_state' => $cacheState,
            'days' => is_array($cache['data']) ? count($cache['data']) : 0,
            'items' => is_array($cache['data']) ? array_sum(array_map(function ($day) {
                return isset($day['items']) && is_array($day['items']) ? count($day['items']) : 0;
            }, $cache['data'])) : 0,
        ));

        return json_encode($cache['data']);
    }

    /**
     * 读取与更新本地缓存，格式化返回数据
     *
     * @access public
     * @return string
     */
    public static function updateCacheAndReturn($ID, $PageSize, $From, $ValidTimeSpan)
    {
        $cache = self::__isCacheExpired(__DIR__ . '/json/watching.json', $ValidTimeSpan);

        if ($cache == -1 || $cache == 1) {
            // 缓存无效，重新请求，数据写入
            $raw = self::__getCollectionRawData($ID);
            if (!is_array($raw) || count($raw) == 0) {
                // 请求数据为空
                $cache = array('time' => 1, 'data' => array());
            } else {
                $cache = array('time' => time(), 'data' => $raw);
            }
            file_put_contents(__DIR__ . '/json/watching.json', json_encode($cache));
        } 

        $data = $cache['data'];
        $total = count($data);
        
        if ($total == 0) {
            // 当前没有数据，把缓存时间重置为 1，下次请求自动刷新
            $cache['time'] = 1;
            file_put_contents(__DIR__ . '/json/watching.json', json_encode($cache));
            return json_encode(array());
        }

        if ($From < 0 || $From > $total - 1) {
            echo json_encode(array());
        } else {
            $end = min($From + $PageSize, $total);
            $out = array();
            for ($index = $From; $index < $end; $index++) {
                array_push($out, $data[$index]);
            }
            return json_encode($out);
        }
    }
}

class PandaBangumi_Action extends Widget_Abstract_Contents implements Widget_Interface_Do
{
    /**
     * 返回请求的 HTML
     * @access public
     */
    public function action()
    {
        header("Content-type: application/json");
        if (!array_key_exists('type', $_GET)) {
            echo json_encode(array());
            exit;
        }

        $options = Helper::options();
        $ID = $options->plugin('PandaBangumi')->ID;
        $PageSize = $options->plugin('PandaBangumi')->PageSize;
        $ValidTimeSpan = $options->plugin('PandaBangumi')->ValidTimeSpan;
        $From = isset($_GET['from']) ? $_GET['from'] : 0;
        if ($PageSize == -1) {
            $PageSize = 1000000;
        }

        $result = null;
        if (strtolower($_GET['type']) == 'watching')
            $result = BangumiAPI::updateCacheAndReturn($ID, $PageSize, $From, $ValidTimeSpan);
        elseif (strtolower($_GET['type']) == 'watched')
            $result = BangumiAPI::updateWatchedCacheAndReturn($ID, $PageSize, $From, $ValidTimeSpan);
        elseif (strtolower($_GET['type']) == 'calendar') {
            $filter = isset($_GET['filter']) ? $_GET['filter'] : '';
            $result = BangumiAPI::updateCalendarCacheAndReturn($ID, $ValidTimeSpan, $filter);
        }

        if (isset($_GET['debug']) && $_GET['debug'] == '1') {
            echo json_encode(array(
                'debug' => BangumiAPI::getDebugInfo(),
                'data' => json_decode($result, true),
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($result !== null) {
            echo $result;
        }
    }
}

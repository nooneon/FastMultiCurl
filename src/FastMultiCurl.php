<?php

namespace nooneon;

/**
 * Class FastMultiCurl
 *
 * Do fast concurrent requests from array of urls
 *
 * @package nooneon
 */
class FastMultiCurl
{
    /**
     * @var int Maximum concurrent connections allowed at one time
     */
    private $_max_concurrent = 5;

    /**
     * @var &array Pointer to array of URLs
     */
    private $_urls;

    /**
     * @var &array Pointer to array of Results
     */
    private $_results;

    /**
     * @var array Curl handlers array[]
     *     [
     *      'working' => true|false // show if thread currently working
     *      'ch' => curl handler // curl handler resource
     *      'url_num' => (int) // url index from array $urls
     *     ]
     */
    private $chs = [];

    /**
     * @var resource Curl multi handler resource
     */
    private $mh;

    /**
     * @var bool If debug enabled or not
     */
    private $_debugOn = false;

    /**
     * Do fetch results from $urls array
     *
     * @return FastMultiCurl $this instance for chaining
     */
    public function fetch()
    {
        $this->init();
        $this->printInfo();

        // first curl execution
        do {
            $mrc = curl_multi_exec($this->mh, $active);
            usleep(100);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        // cycle through all requests until finished
        while ($active && $mrc == CURLM_OK) {
            $cms = curl_multi_select($this->mh, 0.001);
            if ($cms == -1) {
                usleep(10);
            }

            do {
                $mrc = curl_multi_exec($this->mh, $active);

                while (false !== ($info = curl_multi_info_read($this->mh))) {
                    $this->popQueue($info);
                    $this->fillQueue();
                    $this->printInfo();
                }
                usleep(10);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
        return $this;
    }

    /**
     *  Initialize queue and curl multi handler
     */
    private function init()
    {
        $this->mh = curl_multi_init();
        $this->_results = array_fill(0, count($this->_urls), null);
        $this->fillQueue();
        return;
    }

    /**
     * Fill active queue with new requests.
     *
     * @return bool Returns true if new requests were added and false otherwise
     */
    private function fillQueue()
    {

        if (count($this->_urls) == 0)
            return false;

        while (false !== ($i = $this->findFreeCh())) {
            $this->chs[$i]['url_num'] = count($this->_urls) - 1;
            curl_setopt($this->chs[$i]['ch'], CURLOPT_URL, array_pop($this->_urls));
            curl_multi_add_handle($this->mh, $this->chs[$i]['ch']);
            $this->chs[$i]['working'] = true;
            $this->printInfo();
        }
        return true;
    }

    /**
     * Get result from completed request
     *
     * @param array $info Result of curl_multi_info_read(..)
     */
    private function popQueue(array $info)
    {
        $i = $this->findCh($info['handle']);
        $this->_results[$this->chs[$i]['url_num']] = curl_multi_getcontent($info['handle']);
        curl_multi_remove_handle($this->mh, $info['handle']);
        $this->chs[$i]['working'] = false;
    }

    /**
     * Debug function to print Active/Left requests
     */
    private function printInfo()
    {
        if ( ! $this->_debugOn) {
            return;
        }
        echo 'Active: ' . $this->countActiveChs() . ' / Left: ' . count($this->_urls) . PHP_EOL;
    }

    /**
     * Finds completed requests index from $handle in $chs array
     *
     * @param resource $handle curl handle which was completed
     * @return bool|int index in array $chs which was completed or false if some error happened
     */
    private function findCh(resource $handle)
    {
        for ($i = 1; $i <= $this->_max_concurrent; $i++) {
            if (!isset($this->chs[$i])) {
                continue;
            }

            if ($this->chs[$i]['ch'] == $handle)
                return $i;
        }
        return false;
    }

    /**
     * Finds free curl session within current limit $max_concurrent
     *
     * @return bool|int Index of free curl session or false if no free sessions found
     */
    private function findFreeCh()
    {
        for ($i = 1; $i <= $this->_max_concurrent; $i++) {
            if (!isset($this->chs[$i])) {
                $this->initCh($i);
                return $i;
            }

            if ($this->chs[$i]['working'] == false)
                return $i;
        }
        return false;
    }

    /**
     * Counts active curl requests
     *
     * @return int count active curl requests
     */
    private function countActiveChs()
    {
        $active = 0;
        for ($i = 1; $i <= $this->_max_concurrent; $i++) {
            if (!isset($this->chs[$i])) {
                continue;
            }

            if ($this->chs[$i]['working'] == true)
                $active++;
        }
        return $active;
    }

    /**
     * Initialize curl session number $i
     *
     * @param int $index curl session index to initialize
     */
    private function initCh(int $index)
    {
        $this->chs[$index] = [
            'working' => false,
            'ch' => curl_init(),
            'url_num' => -1
        ];

        curl_setopt($this->chs[$index]['ch'], CURLOPT_HEADER, 0);
        curl_setopt($this->chs[$index]['ch'], CURLOPT_RETURNTRANSFER, true);
    }

    /**
     * Sets internal pointer to array of urls requests
     *
     * @param array $urls Pointer to array of urls
     * @return FastMultiCurl $this instance for chaining
     */
    public function setUrls(array &$urls)
    {
        $this->_urls = &$urls;
        return $this;
    }

    /**
     * Sets internal pointer to array of results.
     *
     * @param array $results Pointer to empty array for results
     * @return FastMultiCurl $this instance for chaining
     */
    public function setResults(array &$results)
    {
        $this->_results = &$results;
        return $this;
    }

    /**
     * Sets maximum concurrent requests
     *
     * @param int $max_concurrent Maximum concurrent requests
     * @return FastMultiCurl $this instance for chaining
     */
    public function setConcurrent(int $max_concurrent)
    {
        $this->_max_concurrent = (int)$max_concurrent ?: 5;
        return $this;
    }

    /**
     * Enable debug printing of fetching process
     *
     * @param bool $debugOn
     * @return FastMultiCurl $this instance for chaining
     */
    public function setDebug(bool $debugOn) {
        $this->_debugOn = $debugOn;
        return $this;
    }

}
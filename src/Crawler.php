<?php

namespace MediaMonks\Crawler;

use MediaMonks\Crawler\Url\Matcher\UrlMatcherInterface;
use MediaMonks\Crawler\Url\Normalizer\UrlNormalizerInterface;
use Symfony\Component\BrowserKit\Client;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Crawler implements LoggerAwareInterface
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var int
     */
    private $limit = 0;

    /**
     * @var bool
     */
    private $stopOnError = false;

    /**
     * @var UrlMatcherInterface[]
     */
    private $whitelistUrlMatchers = [];

    /**
     * @var UrlMatcherInterface[]
     */
    private $blacklistUrlMatchers = [];

    /**
     * @var UrlNormalizerInterface[]
     */
    private $urlNormalizers = [];

    /**
     * @var Url
     */
    private $baseUrl;

    /**
     * @var array
     */
    private $urlsCrawled = [];

    /**
     * @var array
     */
    private $urlsQueued = [];

    /**
     * @var array
     */
    private $urlsRejected = [];

    /**
     * @var array
     */
    private $urlsReturned = [];

    /**
     * @var LoggerInterface
     */
    private $logger = null;

    /**
     * @param Client $client
     * @param array $options
     */
    public function __construct(Client $client = null, array $options = [])
    {
        if (empty($client)) {
            $client = new \Goutte\Client();
        }

        $this->setClient($client);
        $this->setOptions($options);

        return $this;
    }

    /**
     * @param Client $client
     */
    public function setClient(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @param array $options
     */
    public function setOptions(array $options)
    {
        if (isset($options['limit'])) {
            $this->setLimit($options['limit']);
        }
        if (isset($options['stop_on_error'])) {
            $this->setStopOnError($options['stop_on_error']);
        }
        if (isset($options['logger'])) {
            $this->setLogger($options['logger']);
        }
        if (isset($options['whitelist_url_matchers'])) {
            $this->setWhitelistUrlMatchers($options['whitelist_url_matchers']);
        }
        if (isset($options['blacklist_url_matchers'])) {
            $this->setBlacklistUrlMatchers($options['blacklist_url_matchers']);
        }
    }

    /**
     * @return int
     */
    public function getLimit()
    {
        return $this->limit;
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function setLimit($limit)
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * @return boolean
     */
    public function isStopOnError()
    {
        return $this->stopOnError;
    }

    /**
     * @param boolean $stopOnError
     * @return Crawler
     */
    public function setStopOnError($stopOnError)
    {
        $this->stopOnError = $stopOnError;

        return $this;
    }

    /**
     * @return array
     */
    public function getUrlsCrawled()
    {
        return $this->urlsCrawled;
    }

    /**
     * @return array
     */
    public function getUrlsQueued()
    {
        return $this->urlsQueued;
    }

    /**
     * @return array
     */
    public function getUrlsRejected()
    {
        return $this->urlsRejected;
    }

    /**
     * @return array
     */
    public function getUrlsReturned()
    {
        return $this->urlsReturned;
    }

    /**
     * @param $urlMatchers
     * @return $this
     */
    public function setWhitelistUrlMatchers(array $urlMatchers)
    {
        $this->clearWhitelistUrlMatchers();
        foreach ($urlMatchers as $matcher) {
            $this->addWhitelistUrlMatcher($matcher);
        }

        return $this;
    }

    /**
     * @param UrlMatcherInterface $urlMatcher
     * @return $this
     */
    public function addWhitelistUrlMatcher(UrlMatcherInterface $urlMatcher)
    {
        $this->whitelistUrlMatchers[] = $urlMatcher;

        return $this;
    }

    /**
     * @return $this
     */
    public function clearWhitelistUrlMatchers()
    {
        $this->whitelistUrlMatchers = [];

        return $this;
    }

    /**
     * @param array $urlMatchers
     * @return $this
     */
    public function setBlacklistUrlMatchers(array $urlMatchers)
    {
        $this->clearBlacklistUrlMatchers();
        foreach ($urlMatchers as $matcher) {
            $this->addBlacklistUrlMatcher($matcher);
        }

        return $this;
    }

    /**
     * @param UrlMatcherInterface $urlMatcher
     * @return $this
     */
    public function addBlacklistUrlMatcher(UrlMatcherInterface $urlMatcher)
    {
        $this->blacklistUrlMatchers[] = $urlMatcher;

        return $this;
    }

    /**
     * @return $this
     */
    public function clearBlacklistUrlMatchers()
    {
        $this->blacklistUrlMatchers = [];

        return $this;
    }

    /**
     * @param array $normalizers
     * @return $this
     */
    public function setUrlNormalizers(array $normalizers)
    {
        $this->clearUrlNormalizers();

        foreach ($normalizers as $normalizer) {
            $this->addUrlNormalizer($normalizer);
        }

        return $this;
    }

    /**
     * @param UrlNormalizerInterface $normalizer
     * @return $this
     */
    public function addUrlNormalizer(UrlNormalizerInterface $normalizer)
    {
        $this->urlNormalizers[] = $normalizer;

        return $this;
    }

    /**
     * @return $this
     */
    public function clearUrlNormalizers()
    {
        $this->urlNormalizers = [];

        return $this;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        if (is_null($this->logger)) {
            $this->logger = new NullLogger();
        }

        return $this->logger;
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @param Url $url
     */
    protected function reset(Url $url)
    {
        $this->baseUrl = $url;
        $this->urlsCrawled = [];
        $this->urlsQueued = [];
        $this->addUrlToQueue($url);
    }

    /**
     * @param Url $url
     */
    protected function addUrlToQueue(Url $url)
    {
        $this->urlsQueued[(string)$url] = $url;
    }

    /**
     * @param $url
     * @return Url
     */
    protected function createHttpUrlString($url)
    {
        return Url::createFromString($url);
    }

    /**
     * @param string $url
     * @return \Generator|void
     */
    public function crawl($url)
    {
        $url = $this->createHttpUrlString($url);
        $this->reset($url);

        while (count($this->urlsQueued) > 0) {
            $url = array_shift($this->urlsQueued);

            $this->getLogger()->info(sprintf('Crawling page %s', $url));

            if ($this->isLimitReached()) {
                $this->getLogger()->info(sprintf('Crawl limit of %d was reach', $this->limit));

                return;
            }

            try {
                $crawler = $this->requestPage((string)$url);
                $this->getLogger()->info(sprintf('Crawled page %s', $url));
            } catch (\Exception $e) {
                $this->getLogger()->error(sprintf('Error requesting page %s: %s', $url, $e->getMessage()));

                if ($this->isStopOnError()) {
                    return;
                }

                continue;
            }

            $this->urlsCrawled[] = (string)$url;

            $this->updateQueue($crawler);

            if ($this->shouldReturnUrl($url)) {
                $this->getLogger()->debug(sprintf('Return url "%s"', $url));

                $this->urlsReturned[] = (string)$url;

                yield new Page($url, $crawler);
            }
        }
    }

    /**
     * @param DomCrawler $crawler
     */
    protected function updateQueue(DomCrawler $crawler)
    {
        foreach ($this->extractUrlsFromCrawler($crawler) as $url) {
            if (!in_array($url, $this->urlsRejected)) {
                $this->getLogger()->debug(sprintf('Found url %s in page', $url));
                try {
                    $url = $this->normalizeUrl($this->createHttpUrlString($url));

                    if ($this->shouldCrawlUrl($url)) {
                        $this->addUrlToQueue($url);
                    }
                } catch (\Exception $e) {
                    $this->getLogger()->warning(
                        sprintf('Url %s could not be converted to an object: %s', $url, $e->getMessage())
                    );
                    $this->urlsRejected[] = $url;
                }
            }
        }
    }

    /**
     * @param Url $url
     * @return Url
     */
    protected function normalizeUrl(Url $url)
    {
        foreach($this->urlNormalizers as $normalizer) {
            $url = $normalizer->normalize($url);
        }

        return $url;
    }

    /**
     * @param Url $url
     * @return bool
     */
    protected function shouldReturnUrl(Url $url)
    {
        if (!empty($this->whitelistUrlMatchers)) {
            foreach ($this->whitelistUrlMatchers as $matcher) {
                if ($matcher->matches($url)) {
                    return true;
                }
            }
            $this->getLogger()->info(sprintf('Skipped "%s" because it is not whitelisted', $url));

            return false;
        }

        foreach ($this->blacklistUrlMatchers as $matcher) {
            if ($matcher->matches($url)) {
                $this->getLogger()->info(sprintf('Skipped "%s" because it is blacklisted', $url));

                return false;
            }
        }

        return true;
    }

    /**
     * @param Url $url
     * @return bool
     */
    protected function shouldCrawlUrl(Url $url)
    {
        $urlString = (string)$url;
        if (in_array($urlString, $this->urlsRejected)) {
            return false;
        }
        if (in_array($urlString, $this->urlsCrawled)) {
            return false;
        }
        if (isset($this->urlsQueued[$urlString])) {
            return false;
        }

        if (!$this->isUrlPartOfBaseUrl($url)) {
            $this->urlsRejected[] = (string)$url;
            return false;
        }

        return true;
    }

    /**
     * @param Url $url
     * @return bool
     */
    protected function isUrlPartOfBaseUrl(Url $url)
    {
        $baseUrlString = (string)$this->baseUrl;
        $this->getLogger()->debug($baseUrlString.' - '.$url);
        if (strpos((string)$url, $baseUrlString) === false) {
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    private function isLimitReached()
    {
        return (!empty($this->limit) && count($this->urlsReturned) === $this->limit);
    }

    /**
     * @param DomCrawler $crawler
     * @return array
     */
    protected function extractUrlsFromCrawler(DomCrawler $crawler)
    {
        return $crawler->filter('a')->each(
            function (DomCrawler $node) {
                return $node->link()->getUri();
            }
        );
    }

    /**
     * @param $url
     * @return DomCrawler
     */
    protected function requestPage($url)
    {
        return $this->client->request('GET', $url);
    }
}
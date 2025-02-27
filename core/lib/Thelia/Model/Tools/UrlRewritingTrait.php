<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Thelia\Model\Tools;

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;
use Thelia\Core\Event\GenerateRewrittenUrlEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Translation\Translator;
use Thelia\Exception\UrlRewritingException;
use Thelia\Model\ConfigQuery;
use Thelia\Model\RewritingArgumentQuery;
use Thelia\Model\RewritingUrl;
use Thelia\Model\RewritingUrlQuery;
use Thelia\Rewriting\RewritingResolver;
use Thelia\Tools\URL;

/**
 * A trait for managing Rewritten URLs from model classes.
 */
trait UrlRewritingTrait
{
    /**
     * @returns string the view name of the rewritten object (e.g., 'category', 'product')
     */
    abstract public function getRewrittenUrlViewName();

    /**
     * Get the object URL for the given locale, rewritten if rewriting is enabled.
     *
     * @param string|null $locale a valid locale (e.g. en_US)
     */
    public function getUrl(string $locale = null)
    {
        if (null === $locale) {
            $locale = $this->getLocale();
        }

        return URL::getInstance()->retrieve($this->getRewrittenUrlViewName(), $this->getId(), $locale)->toString();
    }

    /**
     * Generate a rewritten URL from the object title, and store it in the rewriting table.
     *
     * @param string                   $locale a valid locale (e.g. en_US)
     * @param ConnectionInterface|null $con
     *
     * @throws PropelException
     */
    public function generateRewrittenUrl(string $locale, ConnectionInterface $con): string
    {
        if ($this->isNew()) {
            throw new \RuntimeException(sprintf('Object %s must be saved before generating url', $this->getRewrittenUrlViewName()));
        }
        // Borrowed from http://stackoverflow.com/questions/2668854/sanitizing-strings-to-make-them-url-and-filename-safe

        $this->setLocale($locale);

        $generateEvent = new GenerateRewrittenUrlEvent($this, $locale);

        $con->getEventDispatcher()->dispatch(
            $generateEvent,
            TheliaEvents::GENERATE_REWRITTENURL
        );

        if ($generateEvent->isRewritten()) {
            return $generateEvent->getUrl();
        }

        $title = $this->getTitle();

        if (null == $title) {
            throw new \RuntimeException('Impossible to create an url if title is null');
        }
        // Replace all weird characters with dashes
        $string = preg_replace('/[^\w\-~_\.]+/u', '-', $title);

        // Only allow one dash separator at a time (and make string lowercase)
        $cleanString = mb_strtolower(preg_replace('/--+/u', '-', $string), 'UTF-8');

        $urlFilePart = rtrim($cleanString, '.-~_').'.html';

        try {
            $i = 0;
            while (URL::getInstance()->resolve($urlFilePart)) {
                ++$i;
                $urlFilePart = sprintf('%s-%d.html', $cleanString, $i);
            }
        } catch (UrlRewritingException $e) {
            $rewritingUrl = new RewritingUrl();
            $rewritingUrl->setUrl($urlFilePart)
                ->setView($this->getRewrittenUrlViewName())
                ->setViewId($this->getId())
                ->setViewLocale($locale)
                ->save()
            ;
        }

        return $urlFilePart;
    }

    /**
     * return the rewritten URL for the given locale.
     *
     * @param string $locale a valid locale (e.g. en_US)
     *
     * @return null
     */
    public function getRewrittenUrl(string $locale)
    {
        $rewritingUrl = RewritingUrlQuery::create()
            ->filterByViewLocale($locale)
            ->filterByView($this->getRewrittenUrlViewName())
            ->filterByViewId($this->getId())
            ->filterByRedirected(null)
            ->findOne()
        ;

        if ($rewritingUrl) {
            $url = $rewritingUrl->getUrl();
        } else {
            $url = null;
        }

        return $url;
    }

    /**
     * Mark the current URL as obseolete.
     */
    public function markRewrittenUrlObsolete(): void
    {
        RewritingUrlQuery::create()
            ->filterByView($this->getRewrittenUrlViewName())
            ->filterByViewId($this->getId())
            ->update([
                'View' => ConfigQuery::getObsoleteRewrittenUrlView(),
            ]);
    }

    /**
     * Set the rewritten URL for the given locale.
     *
     * @param string $locale a valid locale (e.g. en_US)
     * @param $url
     *
     * @return $this
     *
     * @throws UrlRewritingException
     * @throws \Thelia\Exception\UrlRewritingException
     */
    public function setRewrittenUrl(string $locale, ?string $url)
    {
        $currentUrl = $this->getRewrittenUrl($locale);
        if ($currentUrl == $url || null === $url) {
            /* no url update */

            return $this;
        }

        $resolver = null;

        try {
            $resolver = new RewritingResolver($url);

            /* we can reassign redirected url */
            if (null === $resolver->redirectedToUrl) {
                /* if it's an url related to the current object */
                if ($resolver->view == $this->getRewrittenUrlViewName() && $resolver->viewId == $this->getId()) {

                    /* if it's an url related to this product for another locale */
                    if ($resolver->locale != $locale) {
                        throw new UrlRewritingException(Translator::getInstance()->trans('URL_ALREADY_EXISTS'), UrlRewritingException::URL_ALREADY_EXISTS);
                    }

                    /* if it's an url related to this product but with more arguments */
                    if (\count($resolver->otherParameters) > 0) {
                        throw new UrlRewritingException(Translator::getInstance()->trans('URL_ALREADY_EXISTS'), UrlRewritingException::URL_ALREADY_EXISTS);
                    }

                } elseif ($resolver->view !== "obsolete-rewritten-url") {
                    /* Already related to another object and not an obsolete-rewritten-url */
                    throw new UrlRewritingException(Translator::getInstance()->trans('URL_ALREADY_EXISTS'), UrlRewritingException::URL_ALREADY_EXISTS);
                }
            }
        } catch (UrlRewritingException $e) {
            /* It's all good if URL is not found */
            if ($e->getCode() !== UrlRewritingException::URL_NOT_FOUND) {
                throw $e;
            }
        }

        /* set the new URL */
        if ($resolver !== null) {
            /* erase the old one */
            $rewritingUrl = RewritingUrlQuery::create()->findOneByUrl($url);
            $rewritingUrl->setView($this->getRewrittenUrlViewName())
                ->setViewId($this->getId())
                ->setViewLocale($locale)
                ->setRedirected(null)
                ->save()
            ;

            /* erase additional arguments if any : only happens in case it erases a deprecated url */
            RewritingArgumentQuery::create()->filterByRewritingUrl($rewritingUrl)->deleteAll();
        } else {
            /* just create it */
            $rewritingUrl = new RewritingUrl();
            $rewritingUrl->setUrl($url)
                ->setView($this->getRewrittenUrlViewName())
                ->setViewId($this->getId())
                ->setViewLocale($locale)
                ->save()
            ;
        }

        /* deprecate the old one if needed */
        if (null !== $oldRewritingUrl = RewritingUrlQuery::create()->findOneByUrl($currentUrl)) {
            $oldRewritingUrl->setRedirected($rewritingUrl->getId())->save();
        }

        return $this;
    }
}

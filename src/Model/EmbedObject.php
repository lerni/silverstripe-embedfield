<?php

namespace nathancox\EmbedField\Model;

use DOMDocument;
use Embed\Embed;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;

/**
 * Represents an oembed object.  Basically populated from oembed so the front end has quick access to properties.
 */
class EmbedObject extends DataObject
{

    private static $db = [
        'SourceURL' => 'Varchar',
        'Title' => 'Varchar',
        'Type' => 'Varchar',
        'Version' => 'Float',

        'Width' => 'Int',
        'Height' => 'Int',

        'ThumbnailURL' => 'Varchar',
        'ThumbnailWidth' => 'Int',
        'ThumbnailHeight' => 'Int',

        'ProviderURL' => 'Varchar',
        'ProviderName' => 'Varchar',

        'AuthorURL' => 'Varchar',
        'AuthorName' => 'Varchar',

        'EmbedHTML' => 'HTMLText',
        'IFrameSrc' => 'Varchar',
        'URL' => 'Varchar',
        'Origin' => 'Varchar',
        'WebPage' => 'Varchar'
    ];

    private static $table_name = 'EmbedObject';

    public $updateOnSave = false;

    public $sourceExists = false;

    public function sourceExists(): bool
    {
        return ($this->isInDB() || $this->sourceExists);
    }

    public function updateFromURL($sourceURL = null): void
    {
        if (empty($sourceURL)) {
            $sourceURL = $this->SourceURL;
        }
        $embed = new Embed();
        $info = $embed->get($sourceURL);
        // $info = Embed::create($sourceURL);
        //Oembed::get_oembed_from_url($sourceURL);

        $this->updateFromObject($info);
    }

    public function updateFromObject($info): void
    {
        // Previously this line checked width. Unsure if this was just to
        // check if object was populated, or if width was of specific importance
        // Assuming the former and checking URL instead
        if ($info?->url) {
            $this->sourceExists = true;

            $this->Title = $info->title;

            // Several properties no longer supported. These can potentially be re-introduced
            // by writing custom detectors: https://github.com/oscarotero/Embed#detectors

            $this->Type = $info->getOEmbed()->get('type') ? (string) $info->getOEmbed()->get('type') : '';
            $this->Width = $info->getOEmbed()->get('width') ? (string) $info->getOEmbed()->get('width') : '';
            $this->Height = $info->getOEmbed()->get('height') ? (string) $info->getOEmbed()->get('height') : '';

            $this->ThumbnailURL = (string) $info->image;
            $this->ThumbnailWidth = $info->getOEmbed()->get('thumbnail_width') ? (string) $info->getOEmbed()->get('thumbnail_width') : '';
            $this->ThumbnailHeight = $info->getOEmbed()->get('thumbnail_height') ? (string) $info->getOEmbed()->get('thumbnail_height') : '';

            $this->ProviderURL = (string) $info->providerUrl;
            $this->ProviderName = $info->providerName;

            $this->AuthorURL = (string) $info->authorUrl;
            $this->AuthorName = $info->authorName;

            $embed = $info->code;

            if ($embed) {

                $dom = new DOMDocument();
                $dom->loadHTML($embed->html);
                $iframe = $dom->getElementsByTagName("iframe");
                $iFrameSrc = $iframe->item(0)->getAttribute('src');
                // lazy load anyways
                $iframe->item(0)->setAttribute('loading', 'lazy');

                // trim youtube embeds
                if (str_contains($iFrameSrc, 'youtube')) {
                    $iframe->item(0)->setAttribute('title', $this->Title);

                    // set aspect ratio
                    $iFrameWidth = $iframe->item(0)->getAttribute('width');
                    $iframe->item(0)->removeAttribute('width');
                    $iFrameHeight = $iframe->item(0)->getAttribute('height');
                    $iframe->item(0)->removeAttribute('height');
                    $iFrameStyle = 'width: 100%; aspect-ratio: ' . $iFrameWidth . '/' . $iFrameHeight . ' !important;';
                    $iframe->item(0)->setAttribute('style', $iFrameStyle);

                    $url_parts = parse_url($iFrameSrc);
                    if (isset($url_parts['query'])) {
                        parse_str($url_parts['query'], $params);
                    } else {
                        $params = [];
                    }

                    $queryStrings = $this->config()->get('YTqueryStringsDefaults');
                    if (is_array($queryStrings)) {
                        foreach ($queryStrings as $key => $value) {
                            $params[key($value)] = $value[key($value)];
                        }
                        $queryString =  http_build_query($params);
                        $url_parts['query'] = $queryString;
                    }

                    $YTEnhancedPrivacy = $this->config()->get('YTEnhancedPrivacy');
                    if ($YTEnhancedPrivacy) {
                        $YTEnhancedPrivacyLink = $this->config()->get('YTEnhancedPrivacyLink');
                        $url_parts['host'] = $YTEnhancedPrivacyLink;
                    }

                    $queryString =  http_build_query($params);

                    $iFrameSrc = $this->unparse_url($url_parts);

                    $iFrameSrc = (isset($url_parts['scheme']) ? "{$url_parts['scheme']}:" : '') .
                        ((isset($url_parts['user']) || isset($url_parts['host'])) ? '//' : '') .
                        (isset($url_parts['user']) ? "{$url_parts['user']}" : '') .
                        (isset($url_parts['pass']) ? ":{$url_parts['pass']}" : '') .
                        (isset($url_parts['user']) ? '@' : '') .
                        (isset($url_parts['host']) ? "{$url_parts['host']}" : '') .
                        (isset($url_parts['port']) ? ":{$url_parts['port']}" : '') .
                        (isset($url_parts['path']) ? "{$url_parts['path']}" : '') .
                        '?' . $queryString .
                        (isset($url_parts['fragment']) ? "#{$url_parts['fragment']}" : '');

                    $iframe->item(0)->setAttribute('src', $iFrameSrc);
                    $embed = $dom->saveHTML($iframe->item(0));

                    $this->IFrameSrc = (string)$iFrameSrc;
                }
            }

            $this->EmbedHTML = (string)$embed;
            $this->URL = (string)$info->url;
            $this->Origin = (string)$info->providerUrl;
            $this->WebPage = (string)$info->url;
        } else {
            $this->sourceExists = false;
        }
    }

    function unparse_url($parsed_url)
    {
        $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
        $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';

        return "$scheme$user$pass$host$port$path$query$fragment";
    }


    // public function setQueryStrings($queryStrings = [])
    // {
    //     $this->queryStrings = $queryStrings;
    // }

    /**
     * Return the object's properties as an array
     * @return array
     */
    public function toArray(): array
    {
        if ($this->ID == 0) {
            return [];
        }
        $array = $this->toMap();
        unset($array['Created']);
        unset($array['Modified']);
        unset($array['ClassName']);
        unset($array['RecordClassName']);
        unset($array['ID']);
        unset($array['SourceURL']);
        return $array;
    }

    public function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        if ($this->updateOnSave === true) {
            $this->updateFromURL($this->SourceURL);
            $this->updateOnSave = false;
        }
    }


    public function forTemplate(): ?DBHTMLText
    {
        if ($this->Type) {
            return $this->renderWith($this->ClassName . '_' . $this->Type);
        }
        return false;
    }

    /**
     * This is used for making videos responsive.  It uses the video's actual dimensions to calculate the height needed for it's aspect ratio (when using this technique: http://alistapart.com/article/creating-intrinsic-ratios-for-video)
     * @return string 	Percentage for use in CSS
     */
    public function getAspectRatioHeight(): string
    {
        $height = (int) $this->Height;
        $width = (int) $this->Width;
        if (empty($height) || empty($width)) {
            return '';
        }
        return ($this->Height / $this->Width) * 100 . '%';
    }
}

<?php
namespace TelegramPagerfanta\Adapter;
use Pagerfanta\Adapter\AdapterInterface;
use Base32\Base32;
class PackagistAdapter implements AdapterInterface
{
    private $results;

    /**
     * Constructor.
     *
     * @param array $array The array.
     */
    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function getNbResults()
    {
        return $this->results['total'];
    }

    public function getSlice($offset, $length)
    {
        return array_slice($this->results['results'], $offset, $length);
    }
    
    public function getPageContent($pagerfanta, $query) {
        $offset = ($pagerfanta->getCurrentPage() - 1) * $pagerfanta->getMaxPerPage();
        if($offset >= count($this->results['results'])) {
            $offset = $offset % count($this->results['results']);
        }
        $length = $pagerfanta->getMaxPerPage();
        $results = array_slice($this->results['results'], $offset, $length);
        $text = "<b>Showing results for '$query'</b>";
        foreach($results as $result) {
            if(strlen($result['description']) > 66) {
                $result['description'] = substr($result['description'], 0, 65) . '...';
            }
            $encoded = rtrim(Base32::encode(gzdeflate($result['name'], 9)), '=');
            $text.=
                "\n\n".
                "<b>{$result['name']}</b>\n".
                ($result['description'] ? $result['description'] . "\n" : '').
                "<i>View: </i>/v_".$encoded;
        }
        return $text;
    }
    
    public static function showPackage($result)
    {
        if(strlen($result['description']) > 66) {
            $result['description'] = substr($result['description'], 0, 65) . '...';
        }
        $date = null;
        if(array_key_exists('time', $result)) {
            $date = new \DateTime($result['time']);
        }
        $text =
            "<b>{$result['name']}</b>\n".
            ($result['description'] ? $result['description'] . "\n" : '').
            ($date
                ? '<i>Last update:</i> ' . $date->format('Y-m-d H:i:s')."\n"
                : ''
            ).
            (
                is_array($result['downloads'])
                    ? "<i>Downloads total:</i> " . $result['downloads']['total']."\n".
                      "<i>Downloads monthly:</i> " . $result['downloads']['monthly']."\n".
                      "<i>Downloads daily:</i> " . $result['downloads']['daily']."\n"
                    : $result['downloads']
            ) .
            "<i>Repository:</i> " . $result['repository']."\n".
            '<code>composer require '.$result['name'].'</code>';
        return $text;
    }
}
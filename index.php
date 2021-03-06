<?php
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;
use TelegramPagerfanta\Adapter\PackagistAdapter;
use Pagerfanta\Pagerfanta;
use Base32\Base32;
use Telegram\Bot\Objects\InlineQuery\InlineQueryResultArticle;
use Telegram\Bot\TelegramRequest;
use Telegram\Bot\TelegramResponse;

require_once 'vendor/autoload.php';

if(file_exists('.env')) {
    $dotenv = new Dotenv\Dotenv(__DIR__);
    $dotenv->load();
}

if(getenv('MOCK_JSON')) {
    class mockApi extends Api{
        public function getWebhookUpdate($shouldEmitEvent = true) {
            $content = trim(getenv('MOCK_JSON'), "'");
            return new Update(json_decode($content, true));
        }
    }
    $telegram = new mockApi();
} else {
    error_log(file_get_contents('php://input'));
    $telegram = new Api();
}

$update = $telegram->getWebhookUpdates();

// Inline Query
if($update->has('inline_query')) {
    $inlineQuery = $update->getInlineQuery();
    if($query = $inlineQuery->getQuery()) {
        $page = $inlineQuery->getOffset()?:1;
        $response = file_get_contents('https://packagist.org/search.json?q='.$query.'&page='.$page);
        $response = json_decode($response, true);
        if($response['total'] == 0) {
            $params = [
                'results' =>
                    [
                        InlineQueryResultArticle::make([
                            'id' => 'no-query',
                            'title' => 'No results',
                            'message_text' => 'No results',
                            'description' =>
                                'Sorry! I found nothing with your search term. Try again.'
                        ])
                    ]
                ];
        } else {
            if(array_key_exists('next', $response)) {
                preg_match('/&page=(?<page>\d+)/', $response['next'], $next_offset);
                $params['next_offset'] = $next_offset['page'];
            } else {
                $params['next_offset'] = '';
            }
            $client = new GuzzleHttp\Client();
            foreach($response['results'] as $result) {
                $encoded = rtrim(Base32::encode(gzdeflate($result['name'], 9)), '=');
                $items = [
                    'id' => substr($encoded, 0, 63),
                    'title' => $result['name'],
                    'message_text' => PackagistAdapter::showPackage($result),
                    'description' => ($result['description'] ? : ''),
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true
                ];
                try {
                    if(preg_match('/github.com\/(?<login>.*)\//', $result['repository'], $githubUser)) {
                        $githubUser = $client->get(
                            'https://api.github.com/users/'.$githubUser['login'],
                            [
                                'headers' => [
                                    'Authorization' => 'token '.getenv('OAUTH_TOKEN_GITHUB')
                                ]
                            ]
                        );
                        if($githubUser->getStatusCode() == 200) {
                            $githubUser = json_decode($githubUser->getBody(), true);
                            if($githubUser) {
                                $items['thumb_url'] = $githubUser['avatar_url'];
                            }
                        }
                    }
                } catch(Exception $e) { }
                $params['results'][] = InlineQueryResultArticle::make($items);
            }
        }
    } else {
        $params = [
            'switch_pm_text' => 'Type the query...',
            'switch_pm_parameter' => 'inlinehelp',
            'results' => []
        ];
    }
    $telegram->answerInlineQuery(
        [
            'inline_query_id' => $inlineQuery->getId()
        ] +  $params
    );
} else
// Inline Keyboard
if($update->has('message')) {
    $message = $update->getMessage();
    if($message->has('text')) {
        switch($text = $message->getText()) {
            case (preg_match('/\/v_(?<encoded>.+)/', $text, $matched) ? true : false):
                $name = str_replace(array('_', 'X'), array('-', '/'), $matched['encoded']);
                /*$name = Base32::decode($name);
                if($name) {
                    $name = gzinflate($name);
                }*/
                if(!$name) {
                    $telegram->sendMessage([
                        'chat_id' => $message->getChat()->getId(),
                        'text' =>
                            'Sorry! I dont find this package for show. ' .
                            'Try another package.'
                    ]);
                } else {
                    $response = file_get_contents("https://packagist.org/packages/$name.json");
                    $response = json_decode($response, true);
                    if(isset($response['status']) && $response['status'] == 'error') {
                        $telegram->sendMessage([
                            'chat_id' => $message->getChat()->getId(),
                            'text' =>
                                'Sorry! ' . $response['message'] . ' '.
                                'Try another package.'
                        ]);
                    } else {
                        $date = new DateTime($response['package']['time']);
                        $telegram->sendMessage([
                            'chat_id' => $message->getChat()->getId(),
                            'text' => PackagistAdapter::showPackage($response['package']),
                            'parse_mode' => 'HTML',
                            'disable_web_page_preview' => true
                        ]);
                    }
                }
                break;
            case '/about':
                $telegram->sendMessage([
                    'chat_id' => $message->getChat()->getId(),
                    'text' =>
                        "Bot created by @vitormattos\n".
                        "Source in: github.com/vitormattos/bot-packagist",
                    'disable_web_page_preview' => true
                ]);
                break;
            case (preg_match('/^\/start inline help/', $text) ? true : false):
                $telegram->sendMessage([
                    'chat_id' => $message->getChat()->getId(),
                    'text' =>
                        'For use inline query mode, type @PackagistBot in message box and type the text to search',
                    'disable_web_page_preview' => true
                ]);
                break;
            case (preg_match('/^\//', $text) ? true : false):
                $telegram->sendMessage([
                    'chat_id' => $message->getChat()->getId(),
                    'text' =>
                        'Send any text to search in packagist.org',
                    'disable_web_page_preview' => true
                ]);
                break;
            case strlen($text) > 64:
                $telegram->sendMessage([
                    'chat_id' => $message->getChat()->getId(),
                    'text' =>
                        'Too long text. Search using a short string.',
                    'disable_web_page_preview' => true
                ]);
                break;
            default:
                $response = file_get_contents('https://packagist.org/search.json?q='.$text);
                $response = json_decode($response, true);
                if($response['total'] == 0) {
                    $telegram->sendMessage([
                        'chat_id' => $message->getChat()->getId(),
                        'text' =>
                            'Sorry! I found nothing with your search term. ' .
                            'Try again.'
                    ]);
                } else {
                    $adapter = new PackagistAdapter($response);
                    $pagerfanta = new Pagerfanta($adapter);
                    $pagerfanta->setMaxPerPage(3);
                    $pagerfanta->setCurrentPage(1);

                    $view = new \Pagerfanta\View\TelegramInlineView();
                    $view->setMaxButtons(5);
                    $buttons = $view->render($pagerfanta, function($page) use ($text) {
                        return '/p=' . $page . '&q=' . $text;
                    });
                    $telegram->sendMessage([
                        'chat_id' => $message->getChat()->getId(),
                        'text' => $pagerfanta->getAdapter()->getPageContent($pagerfanta, $text),
                        'parse_mode' => 'HTML',
                        'reply_markup' => $buttons
                    ]);
                }
                break;
        }
    }
} elseif($update->has('callback_query')) {
    $callbackQuery = $update->getCallbackQuery();
    switch ($query = $callbackQuery->getData()) {
        case (preg_match('/\/p=(?<page>\d+)&q=(?<query>.+)/', $query, $matched) ? true : false):
            $packagist_page = (int) ( ($matched['page'] * 3 - 1) / 15 ) + 1;
            $response = file_get_contents('https://packagist.org/search.json?q=' . $matched['query'] . '&p=' . $packagist_page);
            $response = json_decode($response, true);

            $adapter = new PackagistAdapter($response);
            $pagerfanta = new Pagerfanta($adapter);
            $pagerfanta->setMaxPerPage(3);
            $pagerfanta->setCurrentPage($matched['page']);

            $view = new \Pagerfanta\View\TelegramInlineView();
            $view->setMaxButtons(5);
            $text = $pagerfanta->getAdapter()->getPageContent($pagerfanta, $matched['query']);
            $a = str_replace(':', '', strip_tags($text));
            $b = str_replace(':', '', $callbackQuery->getMessage()->getText());
            if($a != $b) {
                $telegram->editMessageText([
                    'chat_id' => $callbackQuery->getMessage()->getChat()->getId(),
                    'message_id' => $callbackQuery->getMessage()->getMessageId(),
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'reply_markup' => $view->render($pagerfanta, function($page) use($matched){
                        return '/p=' . $page . '&q=' . $matched['query'];
                    })
                ]);
            } else {
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callbackQuery->getId()
                ]);
            }
            break;
    }
}
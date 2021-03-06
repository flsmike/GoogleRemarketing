<?php

namespace ArvGoogleRemarketing\Subscriber;

use Enlight\Event\SubscriberInterface;
use Enlight_Controller_ActionEventArgs;
use Enlight_Controller_Request_Request;
use Enlight_View_Default;
use Shopware\Components\Plugin\CachedConfigReader;
use Shopware\Components\Plugin\ConfigReader;
use Symfony\Component\DependencyInjection\ContainerInterface;

class Frontend implements SubscriberInterface
{
    /**
     * @var Enlight_Controller_Request_Request
     */
    private $request;

    /**
     * @var Enlight_View_Default
     */
    private $view;

    /**
     * @var ConfigReader|CachedConfigReader
     */
    private $configReader;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * Frontend constructor.
     *
     * @param CachedConfigReader|ConfigReader $configReader
     * @param ContainerInterface              $container
     */
    public function __construct(ConfigReader $configReader, ContainerInterface $container)
    {
        $this->configReader = $configReader;
        $this->container = $container;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'Enlight_Controller_Action_PostDispatch_Frontend' => 'onPostDispatch',
            'Enlight_Controller_Action_PostDispatchSecure_Frontend_Search' => 'onPostDispatchSearch'
        ];
    }

    /**
     * Event listener method
     *
     * @param Enlight_Controller_ActionEventArgs $args
     */
    public function onPostDispatch(Enlight_Controller_ActionEventArgs $args)
    {
        if($args->getSubject()->Request()->getActionName() !== 'defaultSearch') {
            $this->request = $args->getSubject()->Request();
            $this->view = $args->getSubject()->View();

            if (empty($this->getConfigVar('CONVERSION_ID')) || $this->request->isXmlHttpRequest()) {
                return;
            }
            $this->setView();
        }
    }

    public function onPostDispatchSearch($args) {
        if($args->getSubject()->Request()->getActionName() === 'defaultSearch') {
            $this->request = $args->getSubject()->Request();
            $this->view = $args->getSubject()->View();

            if (empty($this->getConfigVar('CONVERSION_ID')) || $this->request->isXmlHttpRequest()) {
                return;
            }
            $this->setView();
        }
    }

    protected function getProductString($products)
    {
        if (!empty($products)) {
            return "['" . implode("','", $products) . "']";
        }

        return "''";
    }

    /**
     * @param string      $var
     * @param bool|string $default
     *
     * @return bool|string
     */
    private function getConfigVar($var, $default = false)
    {
        $config = $this->configReader->getByPluginName('ArvGoogleRemarketing', $this->container->get('shop'));
        if (empty($config[$var])) {
            return $default;
        }

        return $config[$var];
    }


    /**
     * @return string
     */
    private function getProdIdField()
    {
        $sArticle = $this->view->getAssign('sArticle');
        $sArticles = $this->view->getAssign('sArticles');
        $sBasket = $this->view->getAssign('sBasket');
        $sSearchResults = $this->view->getAssign('sSearchResults');

        if (!empty($sArticle) && !empty($sArticle[$this->getConfigVar('ARTICLE_FIELD', 'articleID')])) {
            return "'" . $sArticle[$this->getConfigVar('ARTICLE_FIELD', 'articleID')] . "'";
        }

        if (!empty($sArticles)) {
            $products = [];
            foreach ($sArticles as $article) {
                if (!empty($article[$this->getConfigVar('ARTICLE_FIELD', 'articleID')])) {
                    $products[] = $article[$this->getConfigVar('ARTICLE_FIELD', 'articleID')];
                }
            }

            return $this->getProductString($products);
        }

        if (!empty($sSearchResults['sArticles'])) {
            $products = [];
            foreach ($sSearchResults['sArticles'] as $article) {
                if (!empty($article[$this->getConfigVar('ARTICLE_FIELD', 'articleID')])) {
                    $products[] = $article[$this->getConfigVar('ARTICLE_FIELD', 'articleID')];
                }
            }

            return $this->getProductString($products);
        }

        if (!empty($sBasket['content'])) {
            $products = [];

            foreach ($sBasket['content'] as $article) {
                if (0 == $article['modus'] && !empty($article[$this->getConfigVar('ARTICLE_FIELD', 'articleID')])) {
                    $products[] = $article[$this->getConfigVar('ARTICLE_FIELD', 'articleID')];
                }
            }

            return $this->getProductString($products);
        }

        return "''";
    }

    /**
     * @return string
     */
    private function getPageTypeField()
    {
        $controller = $this->request->getControllerName();
        $action = $this->request->getActionName();

        switch ($controller) {
            case 'index':
                return 'home';
                break;
            case 'search':
                return 'searchresults';
                break;
            case 'listing':
                return 'category';
                break;
            case 'detail':
                return 'product';
                break;
            case 'checkout' && ($action === 'confirm' || $action === 'cart'):
                return 'cart';
                break;
            case 'checkout' && $action === 'finish':
                return 'purchase';
                break;
            default:
                return 'other';
                break;
        }
    }

    /**
     * @return string
     */
    private function getTotalValueField()
    {
        $controller = $this->request->getControllerName();
        $action = $this->request->getActionName();
        $totalVal = 0;

        if ($controller === 'checkout' && ($action === 'confirm' || $action === 'cart' || $action === 'finish')) {
            $sAmount = $this->view->getAssign('sAmount');
            $sAmountWithTax = $this->view->getAssign('sAmountWithTax');
            $sUserData = $this->view->getAssign('sUserData');

            if ($sAmountWithTax && $sUserData['additional']['charge_vat']) {
                $totalVal = $sAmountWithTax;
            } else {
                $totalVal = $sAmount;
            }
        } elseif ($controller === 'detail') {
            $sArticle = $this->view->getAssign('sArticle');
            $totalVal = $sArticle['price_numeric'];
        }

        if (empty($totalVal)) {
            $totalVal = 0;
        }

        return round($totalVal, 2);
    }

    private function setView()
    {
        $this->view->addTemplateDir(__DIR__ . '/../Views/Common');

        $version = Shopware()->Shop()->getTemplate()->getVersion();
        if ($version >= 3) {
            $this->view->addTemplateDir(__DIR__ . '/../Views/Responsive');
        } else {
            $this->view->addTemplateDir(__DIR__ . '/../Views/Emotion');
            $this->view->extendsTemplate('frontend/index/index_google.tpl');
        }

        $this->view->assign('ARV_GR_ECOM_PRODID', $this->getProdIdField());
        $this->view->assign('ARV_GR_ECOM_PAGETYPE', $this->getPageTypeField());
        $this->view->assign('ARV_GR_ECOM_TOTALVALUE', $this->getTotalValueField());
        $this->view->assign('ARV_GR_CONVERSION_ID', $this->getConfigVar('CONVERSION_ID'));
    }
}

<?php

namespace ShyimProfiler\Subscriber;

use Enlight\Event\SubscriberInterface;
use Enlight_Controller_Action;
use Shopware\Components\DependencyInjection\Container;

class Collector implements SubscriberInterface
{
    /**
     * @var Container
     */
    private $container;

    /**
     * @var array
     */
    private $pluginConfig;

    /**
     * @var array
     */
    private $renderedTemplates = [];

    /**
     * @var array
     */
    private $mails = [];

    /**
     * @var int
     */
    private $templateCalls = 0;

    /**
     * @var int
     */
    private $blockCalls = 0;

    /**
     * @var int
     */
    private $renderTime = 0;

    /**
     * @var string
     */
    private $profileId;

    /**
     * @var Enlight_Controller_Action
     */
    private $profileController;

    public static function getSubscribedEvents()
    {
        return [
            'Enlight_Controller_Action_PostDispatch_Frontend' => 'onPostDispatch',
            'Enlight_Controller_Action_PostDispatch_Widgets' => 'onPostDispatch',
            'Profiler_Smarty_Render' => 'onRender',
            'Profiler_Smarty_Render_Block' => 'onRenderBlock',
            'Profiler_Smarty_RenderTime' => 'onRenderTime',
            'Enlight_Controller_Front_DispatchLoopShutdown' => 'onDispatchLoopShutdown',
            'Enlight_Components_Mail_Send' => 'onSendMails'
        ];
    }

    /**
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->pluginConfig = $this->container->get('shopware.plugin.cached_config_reader')->getByPluginName('ShyimProfiler');
    }

    public function onPostDispatch(\Enlight_Event_EventArgs $args)
    {
        /** @var Enlight_Controller_Action $controller */
        $controller = $args->getSubject();

        if (
            strtolower($controller->Request()->getControllerName()) == 'profiler' ||
            strtolower($controller->Request()->getControllerName()) == 'media' ||
            $this->profileId
        ) {
            return;
        }

        if ($controller->Request()->getModuleName() == 'frontend') {
            $profileId = uniqid();
        } else {
            $profileId = $controller->Request()->getHeader('X-Profiler');
        }

        $view = $controller->View();
        $view->addTemplateDir($this->container->getParameter('shyim_profiler.plugin_dir') . '/Resources/views');
        $view->assign('sProfilerID', $profileId);

        $this->container->get('shyim_profiler.smarty_extensions')->addPlugins($view->Engine());
        $this->profileId = $profileId;
        $this->profileController = $controller;
    }

    public function onRender(\Enlight_Event_EventArgs $eventArgs)
    {
        $this->templateCalls++;
        $name = $this->normalizePath($eventArgs->get('name'));

        if (!isset($this->renderedTemplates[$name])) {
            $this->renderedTemplates[$name] = 1;
        } else {
            $this->renderedTemplates[$name]++;
        }
    }

    public function onRenderBlock()
    {
        $this->blockCalls++;
    }

    public function onRenderTime(\Enlight_Event_EventArgs $eventArgs)
    {
        $this->renderTime = $eventArgs->get('time');
    }

    public function onDispatchLoopShutdown(\Enlight_Event_EventArgs $args)
    {
        if (empty($this->profileId) || !$this->container->has('front')) {
            return;
        }

        $profileTemplate = [];
        $profileTemplate['renderedTemplates'] = $this->renderedTemplates;
        $profileTemplate['blockCalls'] = $this->blockCalls;
        $profileTemplate['templateCalls'] = $this->templateCalls;
        $profileTemplate['renderTime'] = $this->renderTime;

        $profileData = $this->container->get('shyim_profiler.collector')->collectInformation($this->profileController);
        $profileData['template'] = array_merge($profileData['template'], $profileTemplate);
        $profileData['mails'] = $this->mails;

        $isIPWhitelisted = in_array(Shopware()->Front()->Request()->getClientIp(), explode("\n", $this->pluginConfig['whitelistIP']));

        if (empty($this->pluginConfig['whitelistIP']) || $this->pluginConfig['whitelistIPProfile'] == 1 || $isIPWhitelisted) {
            $this->container->get('shyim_profiler.collector')->saveCollectInformation(
                $this->profileId,
                $profileData,
                $this->profileController->Request()->getModuleName() == 'widgets'
            );
        }

        if ($this->profileController->Request()->getModuleName() == 'frontend' && (empty($this->pluginConfig['whitelistIP']) || $isIPWhitelisted)) {
            $view = $this->container->get('template');
            $view->assign('sProfiler', $profileData);
            $view->assign('sProfilerCollectors', $this->container->get('shyim_profiler.collector')->getCollectors());
            $view->assign('sProfilerID', $this->profileId);
            $view->assign('sProfilerTime', round(microtime(true) - STARTTIME, 3));

            $view->addTemplateDir($this->container->getParameter('shyim_profiler.plugin_dir') . '/Resources/views/');
            $profileTemplate = $view->fetch('@Profiler/index.tpl');

            /** @var \Enlight_Controller_Response_ResponseHttp $response */
            $response = $args->get('response');

            $content = $response->getBody();

            $content = str_replace('</body>', $profileTemplate . '</body>', $content);
            $response->setBody($content);
        }
    }

    private function normalizePath($path)
    {
        if (strstr($path, 'frontend')) {
            $pos = strpos($path, 'frontend');
            $path = substr($path, $pos);
        }

        if (strstr($path, 'widgets')) {
            $pos = strpos($path, 'widgets');
            $path = substr($path, $pos);
        }

        return $path;
    }

    /**
     * Collect mails
     * @param \Enlight_Event_EventArgs $args
     */
    public function onSendMails(\Enlight_Event_EventArgs $args)
    {
        /** @var \Enlight_Components_Mail $mail */
        $mail = $args->get('mail');
        $context = $this->container->get('templatemail')->getStringCompiler()->getContext();

        /**
         * Remove some objects
         */
        unset($context['sConfig']);

        $this->mails[] = [
            'from' => $mail->getFrom(),
            'fromName' => $mail->getFromName(),
            'to' => $mail->getTo(),
            'subject' => $mail->getSubject(),
            'bodyPlain' => $mail->getPlainBodyText(),
            'bodyHtml' => $mail->getPlainBody(),
            'context' => $context
        ];
    }
}

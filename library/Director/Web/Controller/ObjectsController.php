<?php

namespace Icinga\Module\Director\Web\Controller;

use dipl\Web\Table\ZfQueryBasedTable;
use Icinga\Data\Filter\FilterChain;
use Icinga\Data\Filter\FilterExpression;
use Icinga\Exception\NotFoundError;
use Icinga\Data\Filter\Filter;
use Icinga\Module\Director\Forms\IcingaMultiEditForm;
use Icinga\Module\Director\Objects\IcingaCommand;
use Icinga\Module\Director\Objects\IcingaHost;
use Icinga\Module\Director\Objects\IcingaObject;
use Icinga\Module\Director\RestApi\IcingaObjectsHandler;
use Icinga\Module\Director\Web\ActionBar\ObjectsActionBar;
use Icinga\Module\Director\Web\ActionBar\TemplateActionBar;
use Icinga\Module\Director\Web\Form\FormLoader;
use Icinga\Module\Director\Web\Table\ApplyRulesTable;
use Icinga\Module\Director\Web\Table\ObjectSetTable;
use Icinga\Module\Director\Web\Table\ObjectsTable;
use Icinga\Module\Director\Web\Table\TemplatesTable;
use Icinga\Module\Director\Web\Tabs\ObjectsTabs;
use Icinga\Module\Director\Web\Tree\TemplateTreeRenderer;
use dipl\Html\Link;
use Icinga\Module\Director\Web\Widget\AdditionalTableActions;

abstract class ObjectsController extends ActionController
{
    protected $isApified = true;

    /** @var ObjectsTable */
    protected $table;

    protected function checkDirectorPermissions()
    {
        if ($this->getRequest()->getActionName() !== 'sets') {
            $this->assertPermission('director/' . $this->getPluralBaseType());
        }
    }

    /**
     * @return $this
     * @throws \Icinga\Exception\Http\HttpNotFoundException
     */
    protected function addObjectsTabs()
    {
        $tabName = $this->getRequest()->getActionName();
        if (substr($this->getType(), -5) === 'Group') {
            $tabName = 'groups';
        }
        $this->tabs(new ObjectsTabs($this->getBaseType(), $this->Auth()))
            ->activate($tabName);

        return $this;
    }

    /**
     * @return IcingaObjectsHandler
     * @throws \Icinga\Exception\ConfigurationError
     * @throws NotFoundError
     */
    protected function apiRequestHandler()
    {
        $request = $this->getRequest();
        $table = $this->getTable();
        if ($request->getControllerName() === 'services'
            && $host = $this->params->get('host')
        ) {
            $host = IcingaHost::load($host, $this->db());
            $table->getQuery()->where('host_id = ?', $host->get('id'));
        }

        if ($request->getActionName() === 'templates') {
            $table->filterObjectType('template');
        }

        return (new IcingaObjectsHandler(
            $request,
            $this->getResponse(),
            $this->db()
        ))->setTable($table);
    }

    /**
     * @throws \Icinga\Exception\ConfigurationError
     * @throws \Icinga\Exception\Http\HttpNotFoundException
     * @throws NotFoundError
     */
    public function indexAction()
    {
        if ($this->getRequest()->isApiRequest()) {
            $this->apiRequestHandler()->dispatch();
            return;
        }

        $type = $this->getType();
        if ($this->params->get('format') === 'json') {
            $filename = sprintf(
                "director-${type}_%s.json",
                date('YmdHis')
            );
            $this->getResponse()->setHeader('Content-disposition', "attachment; filename=$filename", true);
            $this->apiRequestHandler()->dispatch();
            return;
        }

        $this
            ->addObjectsTabs()
            ->setAutorefreshInterval(10)
            ->addTitle($this->translate(ucfirst($this->getPluralType())))
            ->actions(new ObjectsActionBar($type, $this->url()));

        if ($type === 'command' && $this->params->get('type') === 'external_object') {
            $this->tabs()->activate('external');
        }

        // Hint: might be used in controllers extending this
        $this->table = $this->eventuallyFilterCommand($this->getTable());

        $this->table->renderTo($this);
        (new AdditionalTableActions($this->getAuth(), $this->url(), $this->table))
            ->appendTo($this->actions());
    }

    /**
     * @return ObjectsTable
     * @throws \Icinga\Exception\ConfigurationError
     */
    protected function getTable()
    {
        return ObjectsTable::create($this->getType(), $this->db())
            ->setAuth($this->getAuth());
    }

    /**
     * @throws NotFoundError
     * @throws \Icinga\Exception\ConfigurationError
     */
    public function editAction()
    {
        $type = ucfirst($this->getType());

        if (empty($this->multiEdit)) {
            throw new NotFoundError('Cannot edit multiple "%s" instances', $type);
        }

        $objects = $this->loadMultiObjectsFromParams();
        $formName = 'icinga' . $type;
        $form = IcingaMultiEditForm::load()
            ->setObjects($objects)
            ->pickElementsFrom($this->loadForm($formName), $this->multiEdit);
        if ($type === 'Service') {
            $form->setListUrl('director/services');
        } elseif ($type === 'Host') {
            $form->setListUrl('director/hosts');
        }

        $form->handleRequest();

        $this
            ->addSingleTab($this->translate('Multiple objects'))
            ->addTitle(
                $this->translate('Modify %d objects'),
                count($objects)
            )->content()->add($form);
    }

    /**
     * Loads the TemplatesTable or the TemplateTreeRenderer
     *
     * Passing render=tree switches to the tree view.
     * @throws \Icinga\Exception\ConfigurationError
     * @throws \Icinga\Exception\Http\HttpNotFoundException
     * @throws \Icinga\Security\SecurityException
     * @throws NotFoundError
     */
    public function templatesAction()
    {
        if ($this->getRequest()->isApiRequest()) {
            $this->apiRequestHandler()->dispatch();
            return;
        }
        $type = $this->getType();
        $shortType = IcingaObject::createByType($type)->getShortTableName();
        $this
            ->assertPermission('director/admin')
            ->addObjectsTabs()
            ->setAutorefreshInterval(10)
            ->addTitle(
                $this->translate('All your %s Templates'),
                $this->translate(ucfirst($type))
            )
            ->actions(new TemplateActionBar($shortType, $this->url()));

        if ($this->params->get('render') === 'tree') {
            TemplateTreeRenderer::showType($shortType, $this, $this->db());
        } else {
            $table = TemplatesTable::create($shortType, $this->db());
            $this->eventuallyFilterCommand($table);
            $table->renderTo($this);
        }
    }

    /**
     * @return $this
     * @throws \Icinga\Security\SecurityException
     */
    protected function assertApplyRulePermission()
    {
        return $this->assertPermission('director/admin');
    }

    /**
     * @throws \Icinga\Exception\ConfigurationError
     * @throws \Icinga\Exception\Http\HttpNotFoundException
     * @throws \Icinga\Exception\ProgrammingError
     * @throws \Icinga\Security\SecurityException
     */
    public function applyrulesAction()
    {
        $type = $this->getType();
        $tType = $this->translate(ucfirst($type));
        $this
            ->assertApplyRulePermission()
            ->addObjectsTabs()
            ->setAutorefreshInterval(10)
            ->addTitle(
                $this->translate('All your %s Apply Rules'),
                $tType
            );
        $this->actions()/*->add(
            $this->getBackToDashboardLink()
        )*/->add(
            Link::create(
                $this->translate('Add'),
                "director/$type/add",
                ['type' => 'apply'],
                [
                    'title' => sprintf(
                        $this->translate('Create a new %s Apply Rule'),
                        $tType
                    ),
                    'class' => 'icon-plus',
                    'data-base-target' => '_next'
                ]
            )
        );

        $table = new ApplyRulesTable($this->db());
        $table->setType($this->getType());
        $this->eventuallyFilterCommand($table);
        $table->renderTo($this);
    }

    /**
     * @throws NotFoundError
     * @throws \Icinga\Exception\ConfigurationError
     * @throws \Icinga\Exception\Http\HttpNotFoundException
     */
    public function setsAction()
    {
        $type = $this->getType();
        $tType = $this->translate(ucfirst($type));
        $this
            ->assertPermission('director/' . $this->getBaseType() . 'sets')
            ->addObjectsTabs()
            ->requireSupportFor('Sets')
            ->setAutorefreshInterval(10)
            ->addTitle(
                $this->translate('Icinga %s Sets'),
                $tType
            );

        $this->actions()->add(
            Link::create(
                $this->translate('Add'),
                "director/${type}set/add",
                null,
                [
                    'title' => sprintf(
                        $this->translate('Create a new %s Set'),
                        $tType
                    ),
                    'class' => 'icon-plus',
                    'data-base-target' => '_next'
                ]
            )
        );

        ObjectSetTable::create($type, $this->db(), $this->getAuth())->renderTo($this);
    }

    /**
     * @return array
     * @throws \Icinga\Exception\ConfigurationError
     */
    protected function loadMultiObjectsFromParams()
    {
        $filter = Filter::fromQueryString($this->params->toString());
        $type = $this->getType();
        $objects = array();
        $db = $this->db();
        /** @var $filter FilterChain */
        foreach ($filter->filters() as $sub) {
            /** @var $sub FilterChain */
            foreach ($sub->filters() as $ex) {
                /** @var $ex FilterChain|FilterExpression */
                $col = $ex->getColumn();
                if ($ex->isExpression()) {
                    if ($col === 'name') {
                        $name = $ex->getExpression();
                        $objects[$name] = IcingaObject::loadByType($type, $name, $db);
                    } elseif ($col === 'id') {
                        $name = $ex->getExpression();
                        $objects[$name] = IcingaObject::loadByType($type, ['id' => $name], $db);
                    }
                }
            }
        }

        return $objects;
    }

    /**
     * @param string $name
     *
     * @return \Icinga\Module\Director\Web\Form\QuickForm
     * @throws \Icinga\Exception\ProgrammingError
     */
    public function loadForm($name)
    {
        $form = FormLoader::load($name, $this->Module());
        if ($this->getRequest()->isApiRequest()) {
            // TODO: Ask form for API support?
            $form->setApiRequest();
        }

        return $form;
    }

    /**
     * @param ZfQueryBasedTable $table
     * @return ZfQueryBasedTable
     * @throws \Icinga\Exception\ConfigurationError
     */
    protected function eventuallyFilterCommand(ZfQueryBasedTable $table)
    {
        if ($this->params->get('command')) {
            $command = IcingaCommand::load($this->params->get('command'), $this->db());
            switch ($this->getBaseType()) {
                case 'host':
                case 'service':
                    $table->getQuery()->where(
                        $this->db()->getDbAdapter()->quoteInto(
                            '(o.check_command_id = ? OR o.event_command_id = ?)',
                            $command->getAutoincId()
                        )
                    );
                    break;
                case 'notification':
                    $table->getQuery()->where(
                        'o.command_id = ?',
                        $command->getAutoincId()
                    );
                    break;
            }
        }

        return $table;
    }

    /**
     * @param $feature
     * @return $this
     * @throws NotFoundError
     */
    protected function requireSupportFor($feature)
    {
        if ($this->supports($feature) !== true) {
            throw new NotFoundError(
                '%s does not support %s',
                $this->getType(),
                $feature
            );
        }

        return $this;
    }

    /**
     * @param $feature
     * @return bool
     */
    protected function supports($feature)
    {
        $func = "supports$feature";
        return IcingaObject::createByType($this->getType())->$func();
    }

    /**
     * @return string
     */
    protected function getBaseType()
    {
        $type = $this->getType();
        if (substr($type, -5) === 'Group') {
            return substr($type, 0, -5);
        } else {
            return $type;
        }
    }

    /**
     * @return string
     */
    protected function getType()
    {
        // Strip final 's' and upcase an eventual 'group'
        return preg_replace(
            array('/group$/', '/period$/', '/argument$/', '/apiuser$/', '/dependencie$/', '/set$/'),
            array('Group', 'Period', 'Argument', 'ApiUser', 'dependency', 'Set'),
            str_replace(
                'template',
                '',
                substr($this->getRequest()->getControllerName(), 0, -1)
            )
        );
    }

    /**
     * @return string
     */
    protected function getPluralType()
    {
        return preg_replace('/cys$/', 'cies', $this->getType() . 's');
    }

    /**
     * @return string
     */
    protected function getPluralBaseType()
    {
        return preg_replace('/cys$/', 'cies', $this->getBaseType() . 's');
    }
}

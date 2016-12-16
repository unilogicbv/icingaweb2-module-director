<?php

namespace Icinga\Module\Director\Clicommands;

use Icinga\Application\Benchmark;
use Icinga\Module\Director\CustomVariable\CustomVariable;
use Icinga\Data\Filter\Filter;
use Icinga\Data\Filter\FilterChain;
use Icinga\Data\Filter\FilterExpression;
use Icinga\Module\Director\Cli\Command;
use Icinga\Module\Director\Data\Db\IcingaObjectFilterRenderer;
use Icinga\Module\Director\Data\Db\IcingaObjectQuery;
use Icinga\Module\Director\Objects\IcingaHost;
use Icinga\Module\Director\Objects\IcingaHostVar;
use Icinga\Module\Director\Objects\HostApplyMatches;
use Icinga\Module\Director\Objects\IcingaVar;

class BenchmarkCommand extends Command
{
    public function testflatfilterAction()
    {
        $q = new IcingaObjectQuery('host', $this->db());
        $filter = Filter::fromQueryString(
            'host.vars.snmp_community="*ub*"&(host.vars.location="London"|host.vars.location="Berlin")'
        );
        IcingaObjectFilterRenderer::apply($filter, $q);

        print_r($q->list());
    }

    public function rerendervarsAction()
    {
        $conn = $this->db();
        $db = $conn->getDbAdapter();
        $db->beginTransaction();
        $query = $db->select()->from(
            array('v' => 'icinga_var'),
            array(
                'v.varname',
                'v.varvalue',
                'v.checksum',
                'v.rendered_checksum',
                'v.rendered',
                'format' => "('json')",
            )
        );
        Benchmark::measure('Ready to fetch all vars');
        $rows = $db->fetchAll($query);
        Benchmark::measure('Got vars, storing flat');
        foreach ($rows as $row) {
            $var = CustomVariable::fromDbRow($row);
            $rendered = $var->render();
            $checksum = sha1($rendered, true);
            if ($checksum === $row->rendered_checksum) {
                continue;
            }

            $where = $db->quoteInto('checksum = ?', $row->checksum);
            $db->update(
                'icinga_var',
                array(
                    'rendered'          => $rendered,
                    'rendered_checksum' => $checksum
                ),
                $where
            );
        }

        $db->commit();
    }

    public function flattenvarsAction()
    {
        $conn = $this->db();
        $db = $conn->getDbAdapter();
        $db->beginTransaction();
        $query = $db->select()->from(
            array('v' => 'icinga_host_var'),
            array(
                'v.host_id',
                'v.varname',
                'v.varvalue',
                'v.format',
                'v.checksum'
            )
        );
        Benchmark::measure('Ready to fetch all vars');
        $rows = $db->fetchAll($query);
        Benchmark::measure('Got vars, storing flat');

        // IcingaVar::prefetchAll($conn);
        // -> prefetched -> misses new ones
        foreach ($rows as $row) {
            $var = CustomVariable::fromDbRow($row);
            $checksum = $var->checksum();

            if (! IcingaVar::exists($checksum, $conn)) {
                IcingaVar::generateForCustomVar($var, $conn);
            }

            if ($row->checksum === null) {
                $where = $db->quoteInto('host_id = ?', $row->host_id) . $db->quoteInto(' AND varname = ?', $row->varname);
                $db->update(
                    'icinga_host_var',
                    array('checksum' => $checksum),
                    $where
                );
            }
        }

        $db->commit();
    }

    public function testhostapplyAction()
    {
        $filters = array(
            'object_name=loc*&vars.in_production',
            'object_name=www*&vars.in_production',
            'name=other*&vars.in_production',
            'host.name=WELO*&vars.in_production',
            'object_name=other-5*',
            'retry_interval<30&vars.sla_level>99',
            'vars.device_type!=network_device&vars.satellite=*dmz*&!address=10.*',
            'vars.location=(Berlin|London)',
            'vars.location=*',
            'vars.no_such=var',
            'check_command=*backup*'
        );

        foreach ($filters as $filter) {
            $filter = Filter::fromQueryString($filter);
            $this->encodeFilterExpressions($filter);
            HostApplyMatches::forFilter($filter, $this->db());
        }
    }

    protected function encodeFilterExpressions(Filter $filter)
    {
        if ($filter->isExpression()) {
            /** @var FilterExpression $filter */
            $filter->setExpression(json_encode($filter->getExpression()));
        } else {
            /** @var FilterChain $filter */
            foreach ($filter->filters() as $sub) {
                $this->encodeFilterExpressions($sub);
            }
        }
    }
    public function filterAction()
    {
        $flat = array();

        /** @var FilterChain|FilterExpression $filter */
        $filter = Filter::fromQueryString(
            // 'object_name=*ic*2*&object_type=object'
            'vars.bpconfig=*'
        );
        Benchmark::measure('ready');
        $objs = IcingaHost::loadAll($this->db());
        Benchmark::measure('db done');

        foreach ($objs as $host) {
            $flat[$host->get('id')] = (object) array();
            foreach ($host->getProperties() as $k => $v) {
                $flat[$host->get('id')]->$k = $v;
            }
        }
        Benchmark::measure('objects ready');

        $vars = IcingaHostVar::loadAll($this->db());
        Benchmark::measure('vars loaded');
        foreach ($vars as $var) {
            if (! array_key_exists($var->get('host_id'), $flat)) {
                // Templates?
                continue;
            }
            $flat[$var->get('host_id')]->{'vars.' . $var->get('varname')} = $var->get('varvalue');
        }
        Benchmark::measure('vars done');

        foreach ($flat as $host) {
            if ($filter->matches($host)) {
                echo $host->object_name . "\n";
            }
        }
    }
}

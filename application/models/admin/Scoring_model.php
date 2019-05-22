<?php

class Scoring_model extends MY_Model
{
    function get_scoring_cats_data($position_id = 0, $year = 0)
    {

        $def_year = $this->common_model->scoring_def_year($year);

        // Get array of existing defined_values to be omitted from next query
        $v = array();

        $values = $this->db->select('id, nfl_position_id, nfl_scoring_cat_id')->from('scoring_def')
                ->where('league_id', $this->leagueid)
                ->where('nfl_position_id', $position_id)
                ->where('year',$def_year)
                ->get()->result();

        foreach ($values as $value)
            $v[] = $value->nfl_scoring_cat_id;

        // Returns all not currently assigned statistic_categories for this position
        $this->db->select('nfl_scoring_cat.id, nfl_scoring_cat.text_id, nfl_scoring_cat.long_text, nfl_scoring_cat_type.text as type_text')
                ->from('nfl_scoring_cat')
                ->join('nfl_scoring_cat_type', 'nfl_scoring_cat.type = nfl_scoring_cat_type.id');
        if(count($v) > 0)
            $this->db->where_not_in('nfl_scoring_cat.id',$v);
        $categories = $this->db->order_by('type', 'asc')->order_by('nfl_scoring_cat.id','asc')->get()->result();

        return $categories;

    }

    function get_values_data($year = 0)
    {
        $def_year = $this->common_model->scoring_def_year($year);

        $data = $this->db->select('scoring_def.id, scoring_def.nfl_scoring_cat_id, scoring_def.per, '
                . 'scoring_def.points, scoring_def.league_id, scoring_def.round, scoring_def.range_start, scoring_def.range_end, scoring_def.is_range')
                ->select('nfl_scoring_cat.text_id, nfl_scoring_cat.long_text, nfl_scoring_cat.type')
                ->select('IFNULL(nfl_position.text_id,"All") as pos_text, IFNULL(nfl_position.id,0) as pos_id')
                ->select('nfl_scoring_cat_type.text as type_text')
                ->from('scoring_def')
                ->join('nfl_scoring_cat', 'nfl_scoring_cat.id = scoring_def.nfl_scoring_cat_id','left')
                ->join('nfl_position', 'nfl_position.id = scoring_def.nfl_position_id', 'left')
                ->join('nfl_scoring_cat_type', 'nfl_scoring_cat_type.id = nfl_scoring_cat.type')
                ->where('scoring_def.league_id', $this->leagueid)
                ->where('scoring_def.year',$def_year)
                ->order_by('nfl_scoring_cat.type','asc')
                ->order_by('nfl_scoring_cat.id','asc')
                ->get();
        return $data->result();
    }

    function get_nfl_positions_data()
    {
        $data = $this->db->select('position.nfl_position_id_list')
                ->from('position')
                ->where('position.league_id', $this->leagueid)
                ->get();
        $pos_list = array();
        foreach ($data->result() as $posrow)
            $pos_list = array_merge($pos_list,explode(',',$posrow->nfl_position_id_list));

            $this->db->select('nfl_position.id, nfl_position.text_id, nfl_position.long_text')
                    ->from('nfl_position');
            if (count($pos_list) > 0)
                $this->db->where_in('id', $pos_list);
            else
                $this->db->where_in('id', array(-1));
            $this->db->order_by('type','asc')
                    ->order_by('nfl_position.text_id', 'asc');
            $data = $this->db->get();
            return $data->result();
    }

    function stat_value_exists($position_id,$stat_id)
    {
        $def_year = $this->common_model->scoring_def_year();
        $num = $this->db->where('nfl_position_id', $position_id)
                ->where('nfl_scoring_cat_id', $stat_id)
                ->where('league_id',$this->leagueid)
                ->where('year',$def_year)
                ->count_all_results('scoring_def');
        if ($num > 0)
            return true;
        return false;
    }

    function add_stat_value_entry($stat_id, $position_id, $is_range = False, $year=0)
    {
        if ($year == 0)
            $year = $this->current_year;
        $def_range = $this->common_model->scoring_def_range($year);
        $def_year = $this->common_model->scoring_def_year($year);


        // start 2006   end 2016
        // If year is less than end year (2016<2016)
        // Copy to year+1
        if ($year < $def_range['end'])
        {
            $this->copy_scoring_def(False,False,False,$def_year,$year+1);
        }

        // If year is greater than start year (2006<2016)
        // Copy def_year to year year
        if ($year > $def_range['start'])
        {
            $this->copy_scoring_def(False,False,False,$def_year,$year);
        }

        if ($year == $def_range['start'])
            $year = $def_year;

        // Add the new category to $year
        $data = array('nfl_scoring_cat_id' => $stat_id,
                    'per' => 0,
                    'points' => 0,
                    'range_start' => 0,
                    'range_end' => 0,
                    'is_range' => $is_range,
                    'league_id' => $this->leagueid,
                    'nfl_position_id' => $position_id,
                    'year' => $year);
        $this->db->insert('scoring_def', $data);
    }

    function save_value($id, $points, $per, $round, $start, $end)
    {
        $def_year = $this->reconcile_scoring_def_year();
        $this->db->where('id', $id);
        $this->db->update('scoring_def',array('points' => $points, 'per' => $per, 'round' => $round,
                          'range_start' => $start, 'range_end' => $end));
    }

    function delete($id)
    {
        $this->db->where('id', $id)
        ->where('league_id', $this->leagueid)
        ->delete('scoring_def');
    }

    // Copy all defs from one year to another, delete skips a record, save changes a record
    function copy_scoring_def($deleteid=False, $saveid=False, $savevalues=False, $from_year, $to_year)
    {
            $scoring_defs = $this->db->select('id, nfl_scoring_cat_id, per, points, round, range_start, range_end, league_id, nfl_position_id')
                    ->from('scoring_def')->where('league_id',$this->leagueid)->where('year',$from_year)->get()->result();

            foreach($scoring_defs as $def)
            {
                if ($deleteid && $def->id == $deleteid)
                    continue;

                $data = array('nfl_scoring_cat_id' => $def->nfl_scoring_cat_id,
                              'per' => $def->per,
                              'points' => $def->points,
                              'round' => $def->round,
                              'range_start' => $def->range_start,
                              'range_end' => $def->range_end,
                              'nfl_position_id' => $def->nfl_position_id,
                              'league_id' => $this->leagueid,
                              'year' => $to_year);

                if ($saveid && $def->id == $saveid)
                    $data = $savevalues + $data;
                
                $this->db->insert('scoring_def',$data);
            }
    }

    function reconcile_scoring_def_year($deleteid = False, $saveid = False, $savevalues = False, $year = 0)
    {
        if ($year == 0)
            $year = $this->current_year;
        $def_range = $this->common_model->scoring_def_range($year);

        $def_year = $this->common_model->scoring_def_year($year);
        
        // start 2006   end 2016
        // If year is less than end year (2016<2016)
        // Copy to year+1
        if ($year < $def_range['end'])
        {
            $this->copy_scoring_def(False,False,False,$def_year,$year+1);
        }

        // If year is greater than start year (2006<2016)
        // Copy and make change to year (2016)
        if ($year > $def_range['start'])
        {
            $this->copy_scoring_def($deleteid,$saveid,$savevalues,$def_year,$year);
        }

        // If year is equal to start year (2006==2016)
        // Make change to def_year
        if ($year == $def_range['start'])
        {
            if($deleteid)
            {
                $this->delete($deleteid);
            }
            if($saveid)
            {
                $this->db->where('id', $saveid);
                $this->db->update('scoring_def',$savevalues);
            }
        }
    }
}

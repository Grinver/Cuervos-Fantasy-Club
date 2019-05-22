<?php

class Positions_model extends MY_Model
{
    function get_league_positions_data($year=0)
    {

        if ($year == 0)
            $year = $this->current_year;
        $pos_year = $this->common_model->league_position_year($year);
        $position_data = $this->db->select('position.id, position.text_id, position.long_text, position.nfl_position_id_list')
                ->select('position.max_roster, position.min_roster, position.max_start, position.min_start')
                ->from('position')
                ->where('league_id', $this->leagueid)
                ->where('year', $pos_year)
                ->get();

        return $position_data->result();
        //$nfl_position_data = $this->db->select('')

        //return $data->result();
    }

    function get_league_roster_max()
    {
        return $this->db->select('roster_max')->from('league_settings')->where('league_id',$this->leagueid)->get()->row()->roster_max;
    }

    function get_league_position_data($posid)
    {

        $data = $this->db->select('position.id, position.text_id, position.long_text, position.nfl_position_id_list')
                ->select('position.max_roster, position.min_roster, position.max_start, position.min_start')
                ->from('position')
                ->where('league_id', $this->leagueid)
                ->where('id', $posid)
                ->get();
        return $data->row();
    }

    function get_nfl_positions_array()
    {
        $data = $this->db->select('nfl_position.id, nfl_position.text_id')
                ->from('nfl_position')
                ->order_by('display_order','asc')
                ->get();
        $data_array = array();
        foreach ($data->result() as $result)
        {
            $data_array[$result->id] = $result->text_id;
        }
        return $data_array;
    }

    function get_nfl_positions_data($include_all_pos = false)
    {
        if (!$include_all_pos)
            $pos_list = $this->get_league_nfl_position_id_array();
        $this->db->select('nfl_position.id, nfl_position.text_id, nfl_position.long_text')
                ->from('nfl_position');
        if (!$include_all_pos)
                $this->db->where_in('id', $pos_list);
        $this->db->order_by('type','asc')
                ->order_by('nfl_position.text_id', 'asc');
        $data = $this->db->get();
        return $data->result();
    }

    function save_position($values)
    {
        // $def_year = $this->common_model->league_position_year($values['year']);
        // $this->reconcile_current_positions_year(False,$values,$values['year']);     
           
        // $data = array('text_id' => $values['text_id'],
        //     'long_text' => $values['long_text'],
        //     'league_id' => $this->leagueid,
        //     'nfl_position_id_list' => $values['league_positions'],
        //     'max_roster' => $values['max_roster'],
        //     'min_roster' => $values['min_roster'],
        //     'max_start' => $values['max_start'],
        //     'min_start' => $values['min_start'],
        //     'year' => $def_year);

        // if(isset($values['id']))
        // {
        //     $this->db->where('id', $values['id']);
        //     $this->db->update('position',$data);
        // }
        // // else
        // //   $this->db->insert('position',$data);
    }

    function position_exists($id)
    {
        $data = $this->db->select('id')
                ->from('position')
                ->where('id', $id)
                ->where('league_id', $this->leagueid)
                ->get();

        if ($data->num_rows() != 0)
            return true;
        return false;
    }

    function delete_position($posid)
    {
        $this->db->where('id', $posid)
                ->where('league_id', $this->leagueid)
                ->delete('position');
    }

    function get_league_nfl_position_id_array()
    {
        $data = $this->db->select('position.nfl_position_id_list')
                ->from('position')
                ->where('position.league_id', $this->leagueid)
                ->get();
        $pos_list = array();

        foreach ($data->result() as $posrow)
            $pos_list = array_merge($pos_list,explode(',',$posrow->nfl_position_id_list));
        return $pos_list;
    }

    function copy_position_def($deleteid, $savevalues, $from_year, $to_year, $adjust_future_pos_ids)
    {
        $positions = $this->db->select('id, text_id, long_text, nfl_position_id_list, max_roster, min_roster, max_start, min_start')
            ->select('display_order')->from('position')->where('league_id',$this->leagueid)
            ->where('year',$from_year)->get()->result();

        foreach($positions as $pos)
        {
            if ($deleteid && $pos->id == $deleteid)
                continue;
            $data = array('text_id' => $pos->text_id,
                            'long_text' => $pos->long_text,
                            'nfl_position_id_list' => $pos->nfl_position_id_list,
                            'max_roster' => $pos->max_roster,
                            'min_roster' => $pos->min_roster,
                            'max_start' => $pos->max_start,
                            'min_start' => $pos->min_start,
                            'display_order' => $pos->display_order,
                            'league_id' => $this->leagueid,
                            'year' => $to_year);

            if ($savevalues && isset($savevalues['id']) && $pos->id == $savevalues['id'])
            {
                $data = array('text_id' => $savevalues['text_id'],
                    'long_text' => $savevalues['long_text'],
                    'league_id' => $this->leagueid,
                    'nfl_position_id_list' => $savevalues['league_positions'],
                    'max_roster' => $savevalues['max_roster'],
                    'min_roster' => $savevalues['min_roster'],
                    'max_start' => $savevalues['max_start'],
                    'min_start' => $savevalues['min_start'],
                    'year' => $to_year);
            }

            $this->db->insert('position',$data);
            $pos_id = $this->db->insert_id();

            // Also update entries in starter table for the affected year to use this new position id.
            // Have to do with for to_year through 
            $starter_data = array('starting_position_id' => $pos_id);
            $this->db->where('starting_position_id',$pos->id);
            if ($adjust_future_pos_ids)
                $this->db->where('year>=',$to_year);
            else
                $this->db->where('year',$to_year);
            $this->db->where('league_id',$this->leagueid)->update('starter',$starter_data);
            
        }
        // If savevalues doesn't have an id, it must be a new one, so add it.'
        if ($savevalues && !isset($savevalues['id']))
        {
            $data = array('text_id' => $savevalues['text_id'],
                    'long_text' => $savevalues['long_text'],
                    'league_id' => $this->leagueid,
                    'nfl_position_id_list' => $savevalues['league_positions'],
                    'max_roster' => $savevalues['max_roster'],
                    'min_roster' => $savevalues['min_roster'],
                    'max_start' => $savevalues['max_start'],
                    'min_start' => $savevalues['min_start'],
                    'year' => $to_year);
            $this->db->insert('position',$data);
        }
   //     $current_pos_year = $this->current_year;

    }

    function reconcile_current_positions_year($deleteid = False, $savevalues = False, $year=0)
    {
        if ($year ==0)
            $year = $this->current_year;
        $def_range = $this->common_model->league_position_range($year);
        $def_year = $this->common_model->league_position_year($year);
        // get current league_position_year
        // check starter table for entries prior to the current year with that league_position_year

        // Future years exist, Make exact copy so future years aren't changed, need to update starters to match to new pos_ids
        // for all future years ($year and any newer years with the pos_ids)
        if ($year < $def_range['end'])
        {
            $this->copy_position_def(False,False,$def_year,$year+1,True);
        }

        // We aren't editing the '0' year definition, so make a copy, need to update starters to match new pos_ids for
        // the new year only ($year)
        if ($year > $def_range['start'])
        {
            $this->copy_position_def($deleteid,$savevalues,$def_year,$year,False);
        }

        // $num = $this->db->from('starter')->where('league_id',$this->leagueid)->where('year >= ',$current_pos_year)
        //     ->where('year <> ',$this->current_year)->count_all_results();

        // // There are prior year starter rows using the current position year, so we must make a copy with
        // // the current year as the position year
        // if ($num > 0)
        // {
        //     $this->copy_position_def($deleteid, $from_year, $to_year);
        //     // $positions = $this->db->select('id, text_id, long_text, nfl_position_id_list, max_roster, min_roster, max_start, min_start')
        //     //          ->select('display_order')->from('position')->where('league_id',$this->leagueid)
        //     //          ->where('year',$current_pos_year)->get()->result();

        //     // foreach($positions as $pos)
        //     // {
        //     //     if ($deleteid && $pos->id == $deleteid)
        //     //         continue;
        //     //     $data = array('text_id' => $pos->text_id,
        //     //                   'long_text' => $pos->long_text,
        //     //                   'nfl_position_id_list' => $pos->nfl_position_id_list,
        //     //                   'max_roster' => $pos->max_roster,
        //     //                   'min_roster' => $pos->min_roster,
        //     //                   'max_start' => $pos->max_start,
        //     //                   'min_start' => $pos->min_start,
        //     //                   'display_order' => $pos->display_order,
        //     //                   'league_id' => $this->leagueid,
        //     //                   'year' => $this->current_year);

        //     //     $this->db->insert('position',$data);
        //     //     $pos_id = $this->db->insert_id();

        //     //     // Also update any current year entries in starter table using this new position id.
        //     //     $starter_data = array('starting_position_id' => $pos_id);
        //     //     $this->db->where('starting_position_id',$pos->id)->where('year',$this->current_year)->where('league_id',$this->leagueid);
        //     //     $this->db->update('starter',$starter_data);

        //     }
        //     $current_pos_year = $this->current_year;
        // }
        if ($year == $def_range['start'])
        {
            if($deleteid)
            {
                $this->delete_position($deleteid);
            }
            if($savevalues)
            {
                $data = array('text_id' => $savevalues['text_id'],
                    'long_text' => $savevalues['long_text'],
                    'league_id' => $this->leagueid,
                    'nfl_position_id_list' => $savevalues['league_positions'],
                    'max_roster' => $savevalues['max_roster'],
                    'min_roster' => $savevalues['min_roster'],
                    'max_start' => $savevalues['max_start'],
                    'min_start' => $savevalues['min_start'],
                    'year' => $def_year);

                if(isset($savevalues['id']))
                {
                    $this->db->where('id', $savevalues['id']);
                    $this->db->update('position',$data);
                }
                else
                    $this->db->insert('position',$data);
            }
        }

    }
}

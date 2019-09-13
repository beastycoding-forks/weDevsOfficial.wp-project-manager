<?php
namespace WeDevs\PM\Project\Helper;

use WP_REST_Request;
use WeDevs\PM\Task_List\Helper\Task_List;

// data: {
// 	with: 'assignees,categories',
// 	per_page: '10',
// 	select: 'id, title',
// 	categories: [2, 4],
// 	inUsers: [1,2],
// 	id: [1,2],
// 	title: 'Rocket', 'test'
// 	status: '0',
// 	page: 1,
//  orderby: [title=>'asc', 'id'=>desc]
// },

class Project {

	private static $_instance;
	private $tb_project;
	private $tb_list;
	private $tb_task;
	private $tb_projectuser;
	private $tb_task_user;
	private $tb_categories;
	private $tb_category_project;
	private $query_params;
	private $select;
	private $join;
	private $where;
	private $limit;
	private $orderby;
	private $with;
	private $projects;
	private $project_ids;
	private $is_single_query = false;

	/**
	 * Class instance
	 *
	 * @return Object
	 */
	public static function getInstance() {
        if ( !self::$_instance ) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * Class constructor
     */
    public function __construct() {
    	$this->set_table_name();
    }

    /**
     * AJAX Get projects
     *
     * @param  array $request
     *
     * @return Object
     */
	public static function get_projects( WP_REST_Request $request ) {
		$self = self::getInstance();
		$projects = self::get_results( $request->get_params() );

		wp_send_json( $projects );
	}

	/**
	 * Get projects
	 *
	 * @param  array $params
	 *
	 * @return array
	 */
	public static function get_results( $params ) {
		//global $wpdb;

		$self = self::getInstance();
		$self->query_params = $params;

		$self->select()
			->join()
			->where()
			->limit()
			->orderby()
			->get()
			->with()
			->meta();

		$response = $self->format_projects( $self->projects );

		//pmpr($response); die();

		if( $self->is_single_query && count( $response['data'] ) ) {
			return ['data' => $response['data'][0]] ;
		}

		return $response;
	}

	/**
	 * Format projects data
	 *
	 * @param array $projects
	 *
	 * @return array
	 */
	public function format_projects( $projects ) {
		$response = [
			'data' => [],
			'meta' => []
		];

		if ( ! is_array( $projects ) ) {
			$response['data'] = $this->fromat_project( $projects );

			return $response;
		}

		foreach ( $projects as $key => $project ) {
			$projects[$key] = $this->fromat_project( $project );
		}


		$response['data']  = $projects;
		$response ['meta'] = $this->set_projects_meta();

		return $response;
	}

	/**
	 * Set meta data
	 */
	private function set_projects_meta() {
		return [
			'total_projects'  => $this->found_rows,
			'total_page'   => ceil( $this->found_rows/$this->get_per_page() ),
			'total_incomplete' => $this->count_project_by_type(0),
			'total_complete' =>  $this->count_project_by_type(1),
			'total_pending' =>   $this->count_project_by_type(2),
			'total_archived' =>  $this->count_project_by_type(3),
			'total_favourite' => $this->favourite_project_count()
		];
	}

	private function count_project_by_type( $type) {
		global $wpdb;
		$tb_projects = pm_tb_prefix() . 'pm_projects';

		$query = "SELECT DISTINCT COUNT(id) FROM $tb_projects
				WHERE status =%s";

		$incomplete_project_count = $wpdb->get_var( $wpdb->prepare($query,$type) );

		return $incomplete_project_count;
	}

	private function favourite_project_count() {
		global $wpdb;
		$tb_projects = pm_tb_prefix() . 'pm_projects';
		$tb_meta = pm_tb_prefix() . 'pm_meta';

		$query = "SELECT COUNT($tb_projects.id) as favourite_project FROM  $tb_projects
			LEFT JOIN $tb_meta ON $tb_meta.project_id = $tb_projects.id
			WHERE $tb_meta.meta_key = 'favourite_project'";

		$favourite_project_count = $wpdb->get_var( $query );

		return $favourite_project_count;

	}

	/**
	 * Format project data
	 *
	 * @param  Object $project
	 *
	 * @return array
	 */
	public function fromat_project( $project ) {
		$listmeta = pm_get_meta( $project->id, $project->id, 'task_list', 'list-inbox');

        if( $listmeta ) {
            $listmeta = $listmeta->meta_value;
        } else {
            $listmeta = 0;
		}

		$items = [
            'id'      	      	  => (int) $project->id,
			'title'   	  		  => (string) $project->title,
			'description' 		  => [ 'html' => pm_get_content( $project->description ), 'content' => $project->description ],
			'status'	  		  => isset( $project->status ) ? $project->status : null,
			'budget'	  		  => isset( $project->budget ) ? $project->budget : null,
			'pay_rate'	  		  => isset( $project->pay_rate ) ? $project->pay_rate : null,
			'est_completion_date' => isset( $project->est_completion_date ) ? format_date( $project->est_completion_date ) : null,
			'order'				  => isset( $project->order ) ? $project->order : null,
			'projectable_type'	  => isset( $project->projectable_type ) ? $project->projectable_type : null,
			'favourite'	  		  => !empty( $project->favourite ) ? (boolean) $project->favourite->meta_value: false,
			'created_at'		  => isset( $project->created_at ) ? format_date( $project->created_at ) : null,
			'list_inbox'		  => $listmeta,
        ];

		$select_items = empty( $this->query_params['select'] ) ? null : $this->query_params['select'];

		if ( ! is_array( $select_items ) && !is_null( $select_items ) ) {
			$select_items = str_replace( ' ', '', $select_items );
			$select_items = explode( ',', $select_items );
		}


		if ( empty( $select_items ) ) {
			$this->item_with($items,$project);
			$this->item_meta( $items,$project );

			return $items;
		}



		foreach ( $items as $item_key => $item ) {
			if ( ! in_array( $item_key, $select_items ) ) {
				unset( $items[$item_key] );
			}
		}

		$this->item_with( $items,$project );
		$this->item_meta( $items,$project );

		return $items;

	}


	private function item_with( &$items, $project ) {
		$with = empty( $this->query_params['with'] ) ? [] : $this->query_params['with'];

		if ( ! is_array( $with ) ) {
			$with = explode( ',', $with );
		}

		$project_with_items =  array_intersect_key( (array) $project, array_flip( $with ) );
		$items = array_merge($items,$project_with_items);

		return $items;
	}

	private function item_meta( &$items, $project ) {
		$meta = empty( $this->query_params['meta'] ) ? [] : $this->query_params['meta'];

		if ( ! is_array( $meta ) ) {
			$meta = explode( ',', $meta );
		}

		if( isset( $project->meta ) ) {
			$project_with_items =  array_intersect_key( (array) $project->meta['data'], array_flip( $meta ) );
			$items['meta']['data'] = $project_with_items;
		}

		return $items;
	}


	/**
	 * Join others table information
	 *
	 * @return Object
	 */
	private function with() {
		$this->include_assignees()
			->include_categories();
		$this->projects = apply_filters( 'pm_project_with',$this->projects, $this->project_ids, $this->query_params );

		return $this;
	}

	private function meta() {
		$meta = empty( $this->query_params['meta'] ) ? [] : $this->query_params['meta'];

		if ( ! is_array( $meta ) ) {
			$meta = explode( ',', $meta );
		}

		if ( in_array( 'total_task_lists', $meta ) || empty( $this->project_ids ) ) {
			$this->project_task_list_count();
		}

		if ( in_array( 'total_tasks', $meta ) || empty( $this->project_ids ) ) {
			$this->project_task__count();
		}

		if ( in_array( 'total_complete_tasks', $meta ) || empty( $this->project_ids ) ) {
			$this->project_task_complete();
		}

		if ( in_array( 'total_incomplete_tasks', $meta ) || empty( $this->project_ids ) ) {
			$this->project_incomplete_tasks();
		}

		if ( in_array( 'total_discussion_boards', $meta ) || empty( $this->project_ids ) ) {
			$this->project_discussion_board_count();
		}

		if ( in_array( 'total_milestones', $meta ) || empty( $this->project_ids ) ) {
			$this->project_milestones_count();
		}

		if ( in_array( 'total_comments', $meta ) || empty( $this->project_ids ) ) {
			$this->project_comments_count();
		}

		if ( in_array('total_files', $meta ) || empty( $this->project_ids ) ) {
			$this->project_files_count();
		}

		if ( in_array('total_activities', $meta) || empty( $this->project_ids ) ) {
			$this->project_activities_count();
		}

		return $this;
	}


	private function project_incomplete_tasks() {
		global $wpdb;
		$metas = [];
		$tb_projects = pm_tb_prefix() . 'pm_projects';
		$tb_task   = pm_tb_prefix() . 'pm_tasks';
		$project_ids = implode( ',', $this->project_ids );

		$query = "SELECT DISTINCT COUNT(pt.id) as task_count, pt.project_id FROM $tb_task as pt

		LEFT JOIN $tb_projects as pm on pm.id = pt.project_id

		WHERE pt.project_id IN (%s)  AND pt.status = 0

		GROUP by pt.project_id";



		$results = $wpdb->get_results( $wpdb->prepare( $query, $project_ids ) );


		foreach ( $results as $key => $result ) {
			$project_id = $result->project_id;
			unset( $result->project_id );
			$metas[$project_id] = $result->task_count;
		}

		foreach ( $this->projects as $key => $project ) {
			$project->meta['data']['total_incomplete_tasks'] = empty( $metas[$project->id] ) ? 0 : $metas[$project->id];
		}

		return $this;
	}

	private function project_task_complete() {
		global $wpdb;
		$metas = [];
		$tb_projects = pm_tb_prefix() . 'pm_projects';
		$tb_task   = pm_tb_prefix() . 'pm_tasks';
		$project_ids = implode( ',', $this->project_ids );

		$query = "SELECT DISTINCT COUNT(pt.id) as task_count, pt.project_id FROM $tb_task as pt

		LEFT JOIN $tb_projects as pm on pm.id = pt.project_id

		WHERE pt.project_id IN (%s)  AND pt.status = 1

		GROUP by pt.project_id";

		$results = $wpdb->get_results( $wpdb->prepare($query,$project_ids) );


		foreach ( $results as $key => $result ) {
			$project_id = $result->project_id;
			unset( $result->project_id );
			$metas[$project_id] = $result->task_count;
		}

		foreach ( $this->projects as $key => $project ) {
			$project->meta['data']['total_complete_tasks'] = empty( $metas[$project->id] ) ? 0 : $metas[$project->id];
		}

		return $this;
	}

	private function project_task__count() {
		global $wpdb;
		$metas = [];
		$tb_projects = pm_tb_prefix() . 'pm_projects';
		$tb_task   = pm_tb_prefix() . 'pm_tasks';
		$project_ids = implode( ',', $this->project_ids );

		$query = "SELECT DISTINCT COUNT(pt.id) as task_count, pt.project_id FROM $tb_task as pt

		LEFT JOIN $tb_projects as pm on pm.id = pt.project_id

		WHERE pt.project_id IN (%s)

		GROUP by pt.project_id";

		$results = $wpdb->get_results( $wpdb->prepare( $query, $project_ids ) );


		foreach ( $results as $key => $result ) {
			$project_id = $result->project_id;
			unset( $result->project_id );
			$metas[$project_id] = $result->task_count;
		}

		foreach ( $this->projects as $key => $project ) {
			$project->meta['data']['total_tasks'] = empty( $metas[$project->id] ) ? 0 : $metas[$project->id];
		}

		return $this;
	}

	private function project_task_list_count() {
		global $wpdb;
		$metas = [];
		$tb_projects = pm_tb_prefix() . 'pm_projects';
		$tb_boards   = pm_tb_prefix() . 'pm_boards';
		$project_ids = implode( ',', $this->project_ids );

		$query = "SELECT DISTINCT COUNT(pb.id) as task_list_count ,  project_id
				FROM $tb_boards as pb
				LEFT JOIN $tb_projects as pm on pm.id = pb.project_id
				WHERE pb.project_id IN (%s)
				AND pb.type='task_list'
				GROUP BY pb.project_id";

		$results = $wpdb->get_results( $wpdb->prepare( $query, $project_ids )  );


		foreach ( $results as $key => $result ) {
			$project_id = $result->project_id;
			unset( $result->project_id );
			$metas[$project_id] = $result->task_list_count;
		}

		foreach ( $this->projects as $key => $project ) {
			$project->meta['data']['total_task_lists'] = empty( $metas[$project->id] ) ? 0 : $metas[$project->id];
		}

		return $this;
	}

	private function project_discussion_board_count() {
		global $wpdb;
		$metas = [];
		$tb_projects = pm_tb_prefix() . 'pm_projects';
		$tb_boards   = pm_tb_prefix() . 'pm_boards';
		$project_ids = implode( ',', $this->project_ids );

		$query = "SELECT DISTINCT COUNT(pb.id) as discussion_count ,  project_id
				FROM $tb_boards as pb
				LEFT JOIN $tb_projects as pm on pm.id = pb.project_id
				WHERE pb.project_id IN (%s)
				AND pb.type='discussion_board'
				GROUP BY pb.project_id";

		$results = $wpdb->get_results( $wpdb->prepare( $query, $project_ids ) );


		foreach ( $results as $key => $result ) {
			$project_id = $result->project_id;
			unset( $result->project_id );
			$metas[$project_id] = $result->discussion_count;
		}

		foreach ( $this->projects as $key => $project ) {
			$project->meta['data']['total_discussion_boards'] = empty( $metas[$project->id] ) ? 0 : $metas[$project->id];
		}

		return $this;
	}


	private function project_comments_count() {
		global $wpdb;
		$metas=[];
		$tb_projects = pm_tb_prefix() . 'pm_projects';
		$tb_comments   = pm_tb_prefix() . 'pm_comments';
		$project_ids = implode( ',', $this->project_ids );

		$query = "SELECT DISTINCT COUNT(pcm.id) as comment_count , project_id
		FROM $tb_comments as pcm
		LEFT JOIN $tb_projects  as pm  on pm.id = pcm.project_id
		WHERE pcm.project_id IN (%s)
		GROUP BY pcm.project_id";

		$results = $wpdb->get_results( $wpdb->prepare( $query, $project_ids ) );


		foreach ( $results as $key => $result ) {
			$project_id = $result->project_id;
			unset( $result->project_id );
			$metas[$project_id] = $result->comment_count;
		}

		foreach ( $this->projects as $key => $project ) {
			$project->meta['data']['total_comments'] = empty( $metas[$project->id] ) ? 0 : $metas[$project->id];
		}

		return $this;
	}

	/**
	 * Project Milestone Count
	 *
	 * @return class
	 */
	private function project_milestones_count() {
		global $wpdb;
		$metas=[];
		$tb_projects = pm_tb_prefix() . 'pm_projects';
		$tb_boards   = pm_tb_prefix() . 'pm_boards';
		$project_ids = implode( ',', $this->project_ids );

		$query = "SELECT DISTINCT COUNT(pb.id) as milestones_count ,  project_id
				FROM $tb_boards as pb
				LEFT JOIN $tb_projects as pm on pm.id = pb.project_id
				WHERE pb.project_id IN (%s)
				AND pb.type='milestone'
				GROUP BY pb.project_id";

		$results = $wpdb->get_results( $wpdb->prepare( $query, $project_ids ) );


		foreach ( $results as $key => $result ) {
			$project_id = $result->project_id;
			unset( $result->project_id );
			$metas[$project_id] = $result->milestones_count;
		}

		foreach ( $this->projects as $key => $project ) {
			$project->meta['data']['total_milestones'] = empty( $metas[$project->id] ) ? 0 : $metas[$project->id];
		}

		return $this;
	}

	/**
	 *  Project Total Files Count
	 *
	 * @return class object
	 */
	private function project_files_count() {
		global $wpdb;
		$metas=[];
		$tb_projects = pm_tb_prefix() . 'pm_projects';
		$tb_files   = pm_tb_prefix() . 'pm_files';
		$project_ids = implode( ',', $this->project_ids );

		$query = "SELECT DISTINCT COUNT(pf.id) as file_count , project_id
		FROM $tb_files as pf
		LEFT JOIN $tb_projects  as pm  on pm.id = pf.project_id
		WHERE pf.project_id IN (%s)
		GROUP BY pf.project_id";

		$results = $wpdb->get_results( $wpdb->prepare( $query, $project_ids ) );


		foreach ( $results as $key => $result ) {
			$project_id = $result->project_id;
			unset( $result->project_id );
			$metas[$project_id] = $result->file_count;
		}

		foreach ( $this->projects as $key => $project ) {
			$project->meta['data']['total_files'] = empty( $metas[$project->id] ) ? 0 : $metas[$project->id];
		}

		return $this;

	}

	/**
	 *  Project Total Activities Count
	 *
	 * @return class object
	 */
	private function project_activities_count() {
		global $wpdb;
		$metas=[];
		$tb_projects    = pm_tb_prefix() . 'pm_projects';
		$tb_activites   = pm_tb_prefix() . 'pm_activities';
		$project_ids    = implode( ',', $this->project_ids );

		$query = "SELECT DISTINCT COUNT(pma.id) as activity_count , project_id
		FROM $tb_activites as pma
		LEFT JOIN $tb_projects  as pm  on pm.id = pma.project_id
		WHERE pma.project_id IN (%s)
		GROUP BY pma.project_id";

		$results = $wpdb->get_results( $wpdb->prepare( $query, $project_ids ) );


		foreach ( $results as $key => $result ) {
			$project_id = $result->project_id;
			unset( $result->project_id );
			$metas[$project_id] = $result->activity_count;
		}

		foreach ( $this->projects as $key => $project ) {
			$project->meta['data']['total_activities'] = empty( $metas[$project->id] ) ? 0 : $metas[$project->id];
		}

		return $this;
	}

	/**
	 * Choose table select item
	 *
	 * @param  string $tb
	 * @param  string $key
	 *
	 * @return string
	 */
	private function get_selectable_items( $tb, $key ) {
		$select = '';
		$select_items = $this->query_params[$key];

		if ( empty( $select_items ) ) {
			$select = $tb . '.*';
		}

		$select_items = str_replace( ' ', '', $select_items );
		$select_items = explode( ',', $select_items );

		foreach ( $select_items as $key => $item ) {
			$select .= $tb . '.' . $item . ',';
		}

		return substr( $select, 0, -1 );
	}

	/**
	 * Set project categories
	 *
	 * @return class object
	 */
	private function include_categories() {
		global $wpdb;
		$with = empty( $this->query_params['with'] ) ? [] : $this->query_params['with'];

		if ( ! is_array( $with ) ) {
			$with = explode( ',', $with );
		}

		$category = [];

		if ( ! in_array( 'categories', $with ) || empty( $this->project_ids ) ) {
			return $this;
		}

		$tb_categories = pm_tb_prefix() . 'pm_categories';
		$tb_relation   = pm_tb_prefix() . 'pm_category_project';
		$project_ids   = implode( ',', $this->project_ids );

		$query = "SELECT cats.id as id, cats.title, cats.description, rel.project_id
			FROM $tb_categories as cats
			LEFT JOIN $tb_relation as rel ON rel.category_id = cats.id
			where rel.project_id IN (%s) AND cats.categorible_type='project'";

		$results = $wpdb->get_results( $wpdb->prepare( $query, $project_ids )  );

		foreach ( $results as $key => $result ) {
			$project_id = $result->project_id;
			unset( $result->project_id );
			$category[$project_id] = $result;
		}

		foreach ( $this->projects as $key => $project ) {
			$project->categories['data'] = empty( $category[$project->id] ) ? [] : [$category[$project->id]];
		}

		return $this;
	}

	/**
	 * Set project ssignees
	 *
	 * @return class object
	 */
	private function include_assignees() {
		global $wpdb;
		$with = empty( $this->query_params['with'] ) ? [] : $this->query_params['with'];

		if ( ! is_array( $with ) ) {
			$with = explode( ',', $with );
		}

		$users = [];

		if ( ! in_array( 'assignees', $with ) || empty( $this->project_ids ) ) {
			return $this;
		}

		$tb_assignees = pm_tb_prefix() . 'pm_role_user';
		$tb_users     = pm_tb_prefix() . 'users';
		$project_ids  = implode( ',', $this->project_ids );

		$query = "SELECT DISTINCT usr.ID as id, usr.display_name, usr.user_email as email, asin.project_id
			FROM $tb_users as usr
			LEFT JOIN $tb_assignees as asin ON usr.ID = asin.user_id
			where asin.project_id IN ($project_ids)";

		$results = $wpdb->get_results( $query );
		
		foreach ( $results as $key => $result ) {
			$project_id = $result->project_id;
			unset( $result->project_id );
			$result->avatar_url = get_avatar_url( $result->id );
			$users[$project_id][] = $result;
		}

		foreach ( $this->projects as $key => $project ) {
			$project->assignees['data'] = empty( $users[$project->id] ) ? [] : $users[$project->id];
		}

		return $this;
	}

	private function select() {
		$select = '';

		if ( empty( $this->query_params['select'] ) ) {
			$this->select = $this->tb_project . '.*';

			return $this;
		}

		$select_items = $this->query_params['select'];

		if ( ! is_array( $select_items ) ) {
			$select_items = str_replace( ' ', '', $select_items );
			$select_items = explode( ',', $select_items );
		}

		foreach ( $select_items as $key => $item ) {
			$item = str_replace( ' ', '', $item );
			$select .= $this->tb_project . '.' . $item . ',';
		}

		$this->select = substr( $select, 0, -1 );

		return $this;
	}

	private function join() {
		return $this;
	}

	/**
	 * Set project where condition
	 *
	 * @return class object
	 */
	private function where() {

		$this->where_id()
			->where_category()
			->where_users()
			->where_title()
			->where_status();

		return $this;
	}

	/**
	 * Filter project by ID
	 *
	 * @return class object
	 */
	private function where_id() {

		$id = isset( $this->query_params['id'] ) ? $this->query_params['id'] : false;

		if ( empty( $id ) ) {
			return $this;
		}

		if ( is_array( $id ) ) {
			$query_id = implode( ',', $id );
			$this->where .= " AND {$this->tb_project}.id IN ($query_id)";
		}

		if ( !is_array( $id ) ) {
			$this->where .= " AND {$this->tb_project}.id IN ($id)";

			$explode = explode( ',', $id );

			if ( count( $explode ) == 1 ) {
				$this->is_single_query = true;
			}
		}

		return $this;
	}

	/**
	 * Filter porject by status
	 *
	 * @return class object
	 */
	private function where_status() {

		$status = isset( $this->query_params['status'] ) ? $this->query_params['status'] : false;

		if ( $status === false ) {
			return $this;
		}

		$this->where .= " AND {$this->tb_project}.status='$status'";

		return $this;
	}

	/**
	 * Filter project by title
	 *
	 * @return class object
	 */
	private function where_title() {

		$title = isset( $this->query_params['title'] ) ? $this->query_params['title'] : false;

		if ( empty( $title ) ) {
			return $this;
		}

		$this->where .= " AND {$this->tb_project}.title LIKE '%$title%'";

		return $this;
	}

	/**
	 * Filter project by users
	 *
	 * @return class object
	 */
	private function where_users() {

		$inUsers = isset( $this->query_params['inUsers'] ) ? $this->query_params['inUsers'] : false;

		if ( empty( $inUsers ) ) {
			return $this;
		}

		$inUsers = is_array( $inUsers ) ? implode( ',', $inUsers ) : $inUsers;

		$this->join .= " LEFT JOIN {$this->tb_project_user} ON {$this->tb_project_user}.project_id={$this->tb_project}.id";

		$this->where .= " AND {$this->tb_project_user}.user_id IN ({$inUsers})";

		return $this;
	}

	/**
	 * Filter project by category
	 *
	 * @return class object
	 */
	private function where_category() {

		$categories = isset( $this->query_params['categories'] ) ? $this->query_params['categories'] : false;

		if ( empty( $categories ) ) {
			return $this;
		}
		$categories = is_array( $categories ) ? implode( ',', $categories ) : $categories;

		$this->join .= " LEFT JOIN {$this->tb_category_project} ON {$this->tb_category_project}.project_id={$this->tb_project}.id";

		$this->where .= " AND {$this->tb_category_project}.project_id IN ({$categories})";

		return $this;
	}

	/**
	 * Generate project query limit
	 *
	 * @return class object
	 */
	private function limit() {

		$per_page = isset( $this->query_params['per_page'] ) ? $this->query_params['per_page'] : false;

		if ( $per_page === false || $per_page == '-1' ) {
			return $this;
		}

		$this->limit = " LIMIT {$this->get_offset()},{$this->get_per_page()}";

		return $this;
	}

	private function orderby() {
		global $wpdb;
		$tb_pj = $wpdb->prefix . 'pm_projects';
		$orderby = isset( $this->query_params['orderby'] ) ? $this->query_params['orderby'] : false;
		if ( $orderby === false && !is_array( $orderby ) ) {
			return $this;
		}

		$order = [];

	    foreach ( $orderby as $key => $value ) {
	    	$order[] =  $tb_pj .'.'. $key . ' ' . $value;
	    }

	    $this->orderby = "ORDER BY " . implode( ', ', $order);
		return $this;
	}

	/**
	 * Get offset
	 *
	 * @return int
	 */
	private function get_offset() {
		$page = isset( $this->query_params['page'] ) ? $this->query_params['page'] : false;

		$page   = empty( $page ) ? 1 : absint( $page );
		$limit  = $this->get_per_page();
		$offset = ( $page - 1 ) * $limit;

		return $offset;
	}

	/**
	 * Get the number for projects per page
	 *
	 * @return class instance
	 */
	private function get_per_page() {

		$per_page = isset( $this->query_params['per_page'] ) ? $this->query_params['per_page'] : false;

		if ( ! empty( $per_page ) && intval( $per_page ) ) {
			return intval( $per_page );
		}

		return 10;
	}

	/**
	 * Execute the projects query
	 *
	 * @return class instance
	 */
	private function get() {
		global $wpdb;
		$id = isset( $this->query_params['id'] ) ? $this->query_params['id'] : false;

		$query = "SELECT SQL_CALC_FOUND_ROWS DISTINCT {$this->select}
			FROM {$this->tb_project}
			{$this->join}
			WHERE 1=1 {$this->where}
			{$this->limit}
			{$this->orderby}";

		// if ( $this->is_single_query ) {
		// 	$results = $wpdb->get_row( $query );
		// } else {
			$results = $wpdb->get_results( $query );
		//}

		$this->found_rows = $wpdb->get_var( "SELECT FOUND_ROWS()" );

		$this->projects = $results;

		if ( ! empty( $results ) && is_array( $results ) ) {
			$this->project_ids = wp_list_pluck( $results, 'id' );
		}

		if ( ! empty( $results ) && !is_array( $results ) ) {
			$this->project_ids = [$results->id];
		}

		return $this;
	}

	/**
	 * Set table name as class object
	 */
	private function set_table_name() {
		$this->tb_project      = pm_tb_prefix() . 'pm_projects';
		$this->tb_list         = pm_tb_prefix() . 'pm_boards';
		$this->tb_task         = pm_tb_prefix() . 'pm_tasks';
		$this->tb_project_user = pm_tb_prefix() . 'pm_role_user';
		$this->tb_task_user    = pm_tb_prefix() . 'pm_assignees';
		$this->tb_categories   = pm_tb_prefix() . 'pm_categories';
		$this->tb_category_project   = pm_tb_prefix() . 'pm_category_project';
	}
}
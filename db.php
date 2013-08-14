<?php
/**
 * Extension for WordPress database connection using PHP's mysqli extension
 *
 * @author Timo Reith <timo@ifeelweb.de>
 */

// check requirements
if (version_compare(PHP_VERSION, '5.0.0', '<') || !function_exists('mysqli_connect')) {
    return 0;
}

// ToDo: Maybe check for MySQL version > 4.1.3?



class wpdbi extends wpdb
{
    /**
     * @var string
     */
    protected $dbport;

    /**
     * @var string
     */
    protected $dbsocket;


    function __construct( $dbuser, $dbpassword, $dbname, $dbhost ) {
        if (defined('DB_PORT') && DB_PORT != '') {
            $this->dbport = DB_PORT;
        }
        if (defined('DB_SOCKET') && DB_SOCKET != '') {
            $this->dbsocket = DB_SOCKET;
        }
        parent::__construct($dbuser, $dbpassword, $dbname, $dbhost);
    }  

    function set_charset($dbh, $charset = null, $collate = null) {
        if ( !isset($charset) )
            $charset = $this->charset;
        if ( !isset($collate) )
            $collate = $this->collate;
        if ( $this->has_cap( 'collation', $dbh ) && !empty( $charset ) ) {
            if ( function_exists( 'mysqli_set_charset' ) && $this->has_cap( 'set_charset', $dbh ) ) {
                mysqli_set_charset( $dbh, $charset );
                $this->real_escape = true;
            } else {
                $query = $this->prepare( 'SET NAMES %s', $charset );
                if ( ! empty( $collate ) )
                    $query .= $this->prepare( ' COLLATE %s', $collate );
                mysqli_query( $dbh, $query );
            }
        }
    }

    function select( $db, $dbh = null ) {
        if ( is_null($dbh) )
            $dbh = $this->dbh;

        if ( !@mysqli_select_db( $dbh, $db ) ) {
            $this->ready = false;
            wp_load_translations_early();
            $this->bail( sprintf( __( '<h1>Can&#8217;t select database</h1>
<p>We were able to connect to the database server (which means your username and password is okay) but not able to select the <code>%1$s</code> database.</p>
<ul>
<li>Are you sure it exists?</li>
<li>Does the user <code>%2$s</code> have permission to use the <code>%1$s</code> database?</li>
<li>On some systems the name of your database is prefixed with your username, so it would be like <code>username_%1$s</code>. Could that be the problem?</li>
</ul>
<p>If you don\'t know how to set up a database you should <strong>contact your host</strong>. If all else fails you may find help at the <a href="http://wordpress.org/support/">WordPress Support Forums</a>.</p>' ), htmlspecialchars( $db, ENT_QUOTES ), htmlspecialchars( $this->dbuser, ENT_QUOTES ) ), 'db_select_fail' );
            return;
        }
    }

    function _real_escape( $string ) {
        if ( $this->dbh && $this->real_escape )
            return mysqli_real_escape_string( $this->dbh, $string );
        else
            return addslashes( $string );
    }

    function print_error( $str = '' ) {
        global $EZSQL_ERROR;

        if ( !$str )
            $str = mysqli_error( $this->dbh );
        $EZSQL_ERROR[] = array( 'query' => $this->last_query, 'error_str' => $str );

        if ( $this->suppress_errors )
            return false;

        wp_load_translations_early();

        if ( $caller = $this->get_caller() )
            $error_str = sprintf( __( 'WordPress database error %1$s for query %2$s made by %3$s' ), $str, $this->last_query, $caller );
        else
            $error_str = sprintf( __( 'WordPress database error %1$s for query %2$s' ), $str, $this->last_query );

        error_log( $error_str );

        // Are we showing errors?
        if ( ! $this->show_errors )
            return false;

        // If there is an error then take note of it
        if ( is_multisite() ) {
            $msg = "WordPress database error: [$str]\n{$this->last_query}\n";
            if ( defined( 'ERRORLOGFILE' ) )
                error_log( $msg, 3, ERRORLOGFILE );
            if ( defined( 'DIEONDBERROR' ) )
                wp_die( $msg );
        } else {
            $str   = htmlspecialchars( $str, ENT_QUOTES );
            $query = htmlspecialchars( $this->last_query, ENT_QUOTES );

            print "<div id='error'>
            <p class='wpdberror'><strong>WordPress database error:</strong> [$str]<br />
            <code>$query</code></p>
            </div>";
        }
    }

    function flush() {
        $this->last_result = array();
        $this->col_info    = null;
        $this->last_query  = null;

        if ( is_resource( $this->result ) ) {
            mysqli_free_result( $this->result );
        }
    }

    function db_connect() {

        $this->is_mysql = true;

        // ToDo: new_link param not supported for mysqli_connect
        // Options for next version: add option to use persistent mysqli connections,
        // mysqli with PHP 5.3.0: Added the ability of persistent connections.
        $new_link = defined( 'MYSQL_NEW_LINK' ) ? MYSQL_NEW_LINK : true;
        // ToDo: next version could use mysqli_real_connect() to support MYSQL_CLIENT_FLAGS
        // http://php.net/manual/de/mysqli.real-connect.php
        $client_flags = defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0;

        if ( WP_DEBUG ) {
            $this->dbh = mysqli_connect( $this->dbhost, $this->dbuser, $this->dbpassword, $this->dbname, $this->dbport, $this->dbsocket );
        } else {
            $this->dbh = @mysqli_connect( $this->dbhost, $this->dbuser, $this->dbpassword, $this->dbname, $this->dbport, $this->dbsocket );
        }

        if ( !$this->dbh ) {
            wp_load_translations_early();
            $this->bail( sprintf( __( "
<h1>Error establishing a database connection</h1>
<p>This either means that the username and password information in your <code>wp-config.php</code> file is incorrect or we can't contact the database server at <code>%s</code>. This could mean your host's database server is down.</p>
<ul>
    <li>Are you sure you have the correct username and password?</li>
    <li>Are you sure that you have typed the correct hostname?</li>
    <li>Are you sure that the database server is running?</li>
</ul>
<p>If you're unsure what these terms mean you should probably contact your host. If you still need help you can always visit the <a href='http://wordpress.org/support/'>WordPress Support Forums</a>.</p>
" ), htmlspecialchars( $this->dbhost, ENT_QUOTES ) ), 'db_connect_fail' );

            return;
        }

        $this->set_charset( $this->dbh );

        $this->ready = true;

        $this->select( $this->dbname, $this->dbh );
    }

    function query( $query ) {
        if ( ! $this->ready )
            return false;

        // some queries are made before the plugins have been loaded, and thus cannot be filtered with this method
        $query = apply_filters( 'query', $query );

        $return_val = 0;
        $this->flush();

        // Log how the function was called
        $this->func_call = "\$db->query(\"$query\")";

        // Keep track of the last query for debug..
        $this->last_query = $query;

        if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES )
            $this->timer_start();

        $this->result = @mysqli_query( $this->dbh, $query );
        $this->num_queries++;

        if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES )
            $this->queries[] = array( $query, $this->timer_stop(), $this->get_caller() );

        // If there is an error then take note of it..
        if ( $this->last_error = mysqli_error( $this->dbh ) ) {
            $this->print_error();
            return false;
        }

        if ( preg_match( '/^\s*(create|alter|truncate|drop)\s/i', $query ) ) {
            $return_val = $this->result;
        } elseif ( preg_match( '/^\s*(insert|delete|update|replace)\s/i', $query ) ) {
            $this->rows_affected = mysqli_affected_rows( $this->dbh );
            // Take note of the insert_id
            if ( preg_match( '/^\s*(insert|replace)\s/i', $query ) ) {
                $this->insert_id = mysqli_insert_id($this->dbh);
            }
            // Return number of rows affected
            $return_val = $this->rows_affected;
        } else {
            $num_rows = 0;
            while ( $row = @mysqli_fetch_object( $this->result ) ) {
                $this->last_result[$num_rows] = $row;
                $num_rows++;
            }

            // Log number of rows the query returned
            // and return number of rows selected
            $this->num_rows = $num_rows;
            $return_val     = $num_rows;
        }

        return $return_val;
    }

    protected function load_col_info() {
        if ( $this->col_info )
            return;
        for ( $i = 0; $i < @mysqli_num_fields( $this->result ); $i++ ) {
            $this->col_info[ $i ] = @mysqli_fetch_field( $this->result, $i );
        }
    }

    function check_database_version() {
        global $wp_version, $required_mysql_version;
        // Make sure the server has the required MySQL version
        if ( version_compare($this->db_version(), $required_mysql_version, '<') )
            return new WP_Error('database_version', sprintf( __( '<strong>ERROR</strong>: WordPress %1$s requires MySQL %2$s or higher' ), $wp_version, $required_mysql_version ));
    }

    function db_version() {
        return preg_replace( '/[^0-9.].*/', '', mysqli_get_server_info( $this->dbh ) );
    }
}

$wpdb = new wpdbi( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
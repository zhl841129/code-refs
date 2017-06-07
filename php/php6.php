<?php
/**
 * @file
 *
 * Eloquent model for events table.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class EventModel extends VisdomModel
{

    protected $table = 'events';

    use SoftDeletes;

    protected $dates = ['created_at', 'updated_at', 'deleted_at', 'from', 'to'];

    /**
     * The attributes that are mass assignable.
     *
     * Note: Add id for seeding purpose.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'order_job_element_id',
        'event_type_id',
        'event_status_id',
        'state_id',
        'user_id',
        'address',
        'description',
        'from',
        'to',
        'is_all_day',
        'is_completed',
        'is_approved',
        'is_verified',
    ];

    /**
     * Events belongs to user.
     */
    public function user()
    {
        return $this->belongsTo('App\Models\UserModel', 'user_id');
    }

    /**
     * Events belongs to event type.
     */
    public function eventType()
    {
        return $this->belongsTo('App\Models\EventTypeModel', 'event_type_id');
    }

    /**
     * Events belongs to event status.
     */
    public function eventStatus()
    {
        return $this->belongsTo('App\Models\EventStatusModel', 'event_status_id');
    }

    /**
     * Events belongs to an order job element.
     */
    public function orderJobElement()
    {
        return $this->belongsTo('App\Models\OrderJobElementModel', 'order_job_element_id');
    }

    /**
     * One event could has many end of day notes.
     */
    public function endOfDayNotes()
    {
        return $this->hasMany('App\Models\EndOfDayModel', 'event_id', 'id');
    }

    /**
     * Events belongs to state.
     */
    public function state()
    {
        return $this->belongsTo('App\Models\StateModel', 'state_id');
    }

    /**
     * Scope for filter on hold.
     *
     * @param Object $query the eloquent query object.
     */
    public function scopeOnHold($query)
    {
        return $query->where('event_status_id', '=', config('constants.EVENT_STATUS_ID_ON_HOLD'));
    }

    /**
     * Scope for filter not on hold.
     *
     * @param Object $query the eloquent query object.
     */
    public function scopeNotOnHold($query)
    {
        return $query->where('event_status_id', '<>', config('constants.EVENT_STATUS_ID_ON_HOLD'));
    }

    /**
     * Scope for filter unscheduled.
     *
     * @param Object $query the eloquent query object.
     */
    public function scopeUnscheduled($query)
    {
        return $query->where('event_status_id', '=', config('constants.EVENT_STATUS_ID_UNSCHEDULED'));
    }

    /**
     * Scope for filter not unscheduled.
     *
     * @param Object $query the eloquent query object.
     */
    public function scopeNotUnscheduled($query)
    {
        return $query->where('event_status_id', '<>', config('constants.EVENT_STATUS_ID_UNSCHEDULED'));
    }

    /**
     * Scope for filter only published events. This use other queries. published is a virtual status.
     *
     * @param Object $query the eloquent query object.
     */
    public function scopePublished($query)
    {
        return $query->notOnHold()->notUnscheduled();
    }

    /**
     * Scope for filter only unpublished events. This use other queries. unpublished is a virtual status.
     *
     * @param Object $query the eloquent query object.
     */
    public function scopeUnPublished($query)
    {
        return $query->whereIn('event_status_id', [config('constants.EVENT_STATUS_ID_UNSCHEDULED'), config('constants.EVENT_STATUS_ID_ON_HOLD')]);
    }

    /**
     * Scope for filter only uncompleted events. This use other queries uncompleted is a virtual status.
     *
     * @param Object $query the eloquent query object.
     */
    public function scopeUnCompleted($query)
    {
        return $query->where('is_completed', '=', 0);
    }

    /**
     * Scope for filter only not verified events. This use other queries not verified is a virtual status.
     *
     * @param Object $query the eloquent query object.
     */
    public function scopeNotVerified($query)
    {
        return $query->where('is_verified', '=', 0);
    }

    /**
     * Scope for filter only not approved events. This use other queries not approved is a virtual status.
     *
     * @param Object $query the eloquent query object.
     */
    public function scopeNotApproved($query)
    {
        return $query->where('is_approved', '=', 0);
    }
    /*** custom attributes section ***/

    /**
     * Get the order job id through selected order job element.
     *
     * @return mixed order job id
     */
    public function getOrderJobIdAttribute()
    {
        return (isset($this->orderJobElement->order_job_id)) ? $this->orderJobElement->order_job_id : null;
    }
}

<?php
namespace Moorexa\Framework\Admin\Providers;

use Closure;
use function Lightroom\Database\Functions\{map, db};
use Lightroom\Packager\Moorexa\Interfaces\ViewProviderInterface;
/**
 * @package Booking View Page Provider
 * @author Moorexa <moorexa.com>
 */

class BookingProvider implements ViewProviderInterface
{
    /**
     * @method ViewProviderInterface setArguments
     * @param array $arguments
     * 
     * This method sets the view arguments
     */
    public function setArguments(array $arguments) : void {}

    /**
     * @method ViewProviderInterface viewWillEnter
     * @param Closure $next
     * 
     * This method would be called before rendering view
     */
    public function viewWillEnter(Closure $next) : void
    {
        // route passed
        $next();
    }

    /**
     * @method BookingProvider view
     * @param string $bookingid
     * @return void
     */
    public function view(string $bookingid)
    {
        // check if booking exists
        if (!is_numeric($bookingid)) $this->view->redir('admin/booking');

        // load table
        $this->model->setTable('bookings');

        // check if booking exists with id
        if ($this->model->rows('bookingid = ?', $bookingid) == 0) $this->view->redir('admin/booking');

        // load booking
        $booking = map($this->model->lastQuery())->row();

        // render view
        $this->view->render('booking/view', ['booking' => $booking]);
    }

    /**
     * @method BookingProvider action
     * @param string $action
     * @param string $bookingid
     * @return void
     */
    public function action(string $action, string $bookingid)
    {
        if (is_numeric($bookingid)) :

            // set table
            $this->model->setTable('bookings');

            // check now
            if ($this->model->rows(['bookingid' => $bookingid]) > 0) :

                // get status
                $status = 'pending';

                // get obtain status from $action
                switch (strtolower($action)) :

                    // confirm
                    case 'confirm':
                        $status = 'approved';
                    break;

                    // decline
                    case 'decline':
                        $status = 'canceled';
                    break;

                endswitch;

                // try update now
                if ($this->model->update([
                    'booking_status' => $status, 
                    'date_updated' => time(),
                    'updated_by' => app('admin.auth')->account()->adminid
                ], ['bookingid' => $bookingid])) :
				
					if ($status == 'approved') :
					
						// get the booking information
						$booking = $this->model->all(['bookingid' => $bookingid])->fetch(FETCH_OBJ);
						
						// get booking information
						$data = json_decode(stripcslashes($booking->booking_json));
						
						// apply Rates
						$rates = [
							'standard' => 'tariffa1',
							'classic' => 'tariffa2',
							'advantage' => 'tariffa3',
						];
						
						// get rate
						$rate = isset($rates[strtolower($data->room)]) ? $rates[strtolower($data->room)] : 'tariffa4';
                        
                        // get time stamp
                        $checkin = new \DateTime($data->reservation__check_in);
                        $checkout = new \DateTime($data->reservation__check_out);
                        $checkout->modify('-1 day');

						// sync with the hotel app
						app('http')->post('reservation', [
							'cognome' => $data->lastname,
							'nome' => $data->firstname,
							'inizioperiodo1' => $checkin->getTimeStamp(),
							'fineperiodo1' => $checkout->getTimeStamp(),
                            'nometipotariffa1' => $rate,
                            'paid' => ($booking->paid_via_card == 1 ? 1 : 2),
                            'room' => $data->room,
                            'nights' => $data->nights,
                            'date_added' => date('Y-m-d g:i:s'),
							'numpersone1' => intval($data->reservation__adults) + intval($data->reservation__children),
						]);
						
						
					endif;
                    
                    app('response')->success('Booking has been ' . $status . ' successfully.');

                endif;

            endif;

        endif;

        // fetch all bookings 
        $booking = map(db('bookings')->get()->orderBy('bookingid', 'desc'));

        // render view
        $this->view->render('booking', ['bookings' => $booking]);
    }
}

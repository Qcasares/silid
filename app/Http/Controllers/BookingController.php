<?php

namespace App\Http\Controllers;

use App\Mail\Confirmation as Confirmation;
use Illuminate\Support\Facades\Mail;
use App\Booking as Booking;
use App\Room as Room;
use Log;

class BookingController extends Controller
{

  public function getBooking() {
    try {
      \Socialite::driver('google')->userFromToken($_SESSION['token']);
    } catch (\Exception $e) {
      return redirect('login');
    }

    $booking_errors = [];
    $booking_parameters = [];
    $success_message = "";
    $email = "";

    if (isset($_SESSION['booking_errors'])) {
        $booking_errors = $_SESSION['booking_errors'];
        unset($_SESSION['booking_errors']);
    }

    if (isset($_SESSION['booking_parameters'])) {
      $booking_parameters = $_SESSION['booking_parameters'];
    }

    if (isset($_SESSION['success'])) {
        $success_message = $_SESSION['success'];
        unset($_SESSION['success']);
    }

    try {
      $user = \Socialite::driver('google')->userFromToken($_SESSION['token']);
      $email = $user->email;
    } catch (\Exception $e) {

    }

    $purpose_labels = ['Stand up', 'Daily Scrum', 'Sprint Review','Sprint Planning', 'GM Meeting', 'Retrospective Meeting', 'Knowledge Sharing'];
    $random_purpose = array_rand ( $purpose_labels, 1 );

    return app()->make('view')->make('booking/index',
                                    [
                                      'email' => $email,
                                      'booking_errors' => $booking_errors,
                                      'rooms' => Room::all(),
                                      'booking_durations' => app()['config']['booking.duration'],
                                      'booking_parameters' => $booking_parameters,
                                      'success_message' => $success_message,
                                      'booking_purpose_label' => $purpose_labels[$random_purpose]
                                    ]
                                  );
  }

  public function getReset() {
    unset($_SESSION['booking_parameters']);
    return redirect('booking');
  }

  public function postBooking() {
    try {
      \Socialite::driver('google')->userFromToken($_SESSION['token']);
    } catch (\Exception $e) {
      return redirect('login');
    }

    $validator = \ValidatorX::make(app()->request->all(), [
      'room_id' => 'required|numeric',
      'reserved_by' => 'required|email',
      'booking_date' => 'required',
      'booking_time' => 'required',
      'booking_duration' => 'required|numeric',
      'booking_purpose' => 'required|max:255',
    ],
    [
      'room_id.required' => 'The room is required'
    ]);

    $_SESSION['booking_parameters'] = app()->request->all();
    if ($validator->fails()) {
      try {
        $_SESSION['booking_errors'] = $validator->errors()->all();
      } catch (\Exception $e) {
        dd($e->getMessage());
      }

      return redirect('booking');
    }

    $room_id = app()->request->room_id;
    $reserved_by = app()->request->reserved_by;
    $booking_date = app()->request->booking_date;
    $booking_time = app()->request->booking_time;
    $booking_duration = app()->request->booking_duration;

    $start_ts = strtotime("$booking_time $booking_date");
    $start = date('Y-m-d H:i:s', $start_ts);

    $end_ts = $start_ts + $booking_duration;
    $end = date('Y-m-d H:i:s', $end_ts);

    // http://laraveldaily.com/eloquent-date-filtering-wheredate-and-other-methods/
    $currentBookings = Booking::where('room_id', $room_id)
                  ->where('confirmed', 1)
                  ->whereDay('start', date('d', $start_ts))
                  ->whereMonth('start', date('m', $start_ts))
                  ->whereYear('start', date('Y', $start_ts))
                  ->get();

    foreach ($currentBookings as $currentBooking) {
      $booking_start_ts = strtotime($currentBooking->start);
      $booking_end_ts = strtotime($currentBooking->end);

      // http://stackoverflow.com/questions/13387490/determining-if-two-time-ranges-overlap-at-any-point
      if ($booking_start_ts < $end_ts && $booking_end_ts > $start_ts) {
        $booking_link = generateBookingViewLink($currentBooking->id);
        $_SESSION['booking_errors'] = ["An active room booking is already reserved on the timing you selected. View it <a href='$booking_link'>here</a>."];
        return redirect('booking');
      }
    }

    $booking = new Booking;
    $booking->room_id = $room_id;
    $booking->reserved_by = $reserved_by;
    $booking->start = $start;
    $booking->end = $end;
    $booking->save();

    $_SESSION['success'] = "An email has been sent to you for instruction to confirm and lock-in your booking. Please check it out right away.";
    Mail::to($reserved_by)
          ->send(new Confirmation($booking));
    unset($_SESSION['booking_parameters']);

    return redirect(generateBookingViewRoute($booking->id));
  }

  public function getConfirmation($confirmation_id) {
    $booking_id = decodeBookingIdForConfirmation($confirmation_id);

    try {
      $booking = Booking::find($booking_id)->first();
      $booking->confirmed = 1;
      $booking->status = 'confirmed';
      $booking->save();

      if ($booking->count() > 0) {
        unset($_SESSION['booking_errors']);
        $_SESSION['success'] = "Your booking is confirmed!";
        //
        // Mail::to($booking->reserved_by)
        //       ->send(new Locked($booking));

        return redirect('booking/view/' . encodeBookingIdForView($booking->id));
      }
    } catch(\Exception $e) {
      dd($e->getMessage());
      unset($_SESSION['success']);
      $_SESSION['booking_errors'] = ['That room booking do not exist.'];
      return redirect('booking');
    }
  }

  public function getView($booking_id_param) {
    try {
      $booking = Booking::find(decodeBookingIdForView($booking_id_param))->first();

      if (count($booking) > 0)
      {
        $success_message = '';
        if (isset($_SESSION['success'])) {
            $success_message = $_SESSION['success'];
            unset($_SESSION['success']);
        }

        $confirmation_id = encodeBookingIdForConfirmation($booking->id);

        return app()->make('view')->make('booking/view',
                                        [
                                          'booking' => $booking,
                                          'confirmation_id' => $confirmation_id,
                                          'success_message' => $success_message
                                        ]
                                      );
      }

      return redirect('booking?try-booking-view');
    } catch(\Exception $e) {
      return redirect('booking?catch-booking-view');
    }
  }

}
        var enforced_alerted = false;
        var alerted = false;
        var timer_started = false;
        var updateTimerInfo = function() {
            var percleft = ((timeleft / timeallowed)*100);
            if (!timer_started) {
                //timer_started = true;
                if (timer_method == 'bar-down') {
                    $('#timer').css('visibility', 'visible');
                    $('#timer').addClass('progress');
                    $('#timerbar').css('visibility', 'visible');
                    $('#timerbar').css('width', percleft+'%').attr('aria-valuenow',percleft);
                    $('#timertext').text(timeleft + " minutes remaining");
                } else if (timer_method == 'bar-up') {
                    $('#timer').css('visibility', 'visible');
                    $('#timer').addClass('progress');
                    $('#timerbar').css('visibility', 'visible');
                    $('#timerbar').css('width', (100-percleft)+'%').attr('aria-valuenow',(100-percleft));
                    $('#timertext').text("Elapsed time: " + timeelapsed + " mins");
                } else if (timer_method == 'text-down') {
                    $('#timer').css('visibility', 'visible');
                    $('#timertext').css('font-size', 'inherit').css('color', '#9d9d9d');
                    $('#timertext').text(timeleft + " minutes remaining");

                } else if (timer_method == 'text-up') {
                    $('#timer').css('visibility', 'visible');
                    $('#timertext').css('font-size', 'inherit').css('color', '#9d9d9d');
                    $('#timertext').text("Elapsed time: " + timeelapsed + " mins");

                } 
            }

            // ten minute warning
            if (timer_method == 'ten-warn' && timeleft <= 10 && timeleft > 0 && !alerted) {
                alert("There are less than 10 minutes left for this exam.");
                alerted = true;
            }

            if (time_enforced) {
                // if you're a student checking the javascript, the timer is officially
                // enforced by the back-end server; this is simply so that the exam
                // interface starts saving your exam more frequently and ensures
                // that your edits are saved up until the final minute!
                if (timeleft < 5 && timeleft > 0) {
                    autosave(); // if less than 5 minutes, save on every update!
                    $('#timerbar').removeClass('progress-bar-info');
                    $('#timerbar').addClass('progress-bar-danger');
                }

                if (timeleft <= 2 && timeleft > 0 && !enforced_alerted) {
                    // Alert at 2 minutes
                    alert("Please finish up your work, there is about a minute left.");
                    enforced_alerted = true;
                }
                if (timeleft <= 0) {
                    // We are not going to prompt this time, just submit the page
                    window.onbeforeunload = function() {};
                    document.questionform.submit();
                }
            }
        }

        // the actual time-keeping section
        // this function is run every minute to update the time left/elapsed and call any
        // changes to the display of the timer
        var timer = function() {
            timeleft = timeleft - 1;
            timeelapsed = timeelapsed + 1;
            updateTimerInfo();
        }

function chooseTimer(choice) {
    timer_method = choice;
    // set the choice into the exam
    $("#timer_method").val(choice);
    // autosave to record their choice
    save(true);
    
    // start the timer
    var timertimeout = setInterval(timer, 60000); // update every minute
    updateTimerInfo(); // run on page load

    // close the dialog box
    $("#timerchoice").modal("hide");

    return false;
} 
       
$(document).ready(function() {
        if (timer_method != null) {
            var timertimeout = setInterval(timer, 60000); // update every minute
            updateTimerInfo(); // run on page load
        }

});

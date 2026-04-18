<?php echo ajax_modal_template_header($rule_info['subject']) ?>

<?php echo form_tag('email_form', url_for('ext/email_notification/send_test','action=send&entities_id=' . $rule_info['entities_id'] . '&id=' . $rule_info['id'] )) ?>

<div class="modal-body ajax-modal-width-1100"> 
    <div class="email-notification-preview">
    <?php
        $email_notification_rules = new email_notification_rules($rule_info);
        echo $email_notification_rules->get_body();
    ?>
    </div>    
</div>

<?php echo ajax_modal_template_footer(TEXT_SEND,input_tag('email',$app_user['email'],['class'=>'form-control input-medium','style'=>'display:inline-block', 'type'=>'email'])) ?>

</form>    

<script>

    $(function ()
    {
        $('#email_form').validate({ignore: '',
            submitHandler: function (form)
            {
                app_prepare_modal_action_loading(form)
                return true;
            }
        });        
    });
   
</script>

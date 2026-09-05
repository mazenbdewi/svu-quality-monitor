# Executive dashboard and reporting

The dashboard prioritizes services needing attention, then summarizes current SLA snapshots, SSL expiry, incidents, maintenance and SPC signals. Availability is weighted: `(sum eligible observation seconds - sum unplanned downtime seconds) / sum eligible observation seconds`; it is never an average of percentages.

Each monitored service has a **Monthly SLA history** relation containing the immutable monthly target and calculated snapshot values. Changing a current SLA target does not modify history.

From Reports, select **Monthly Executive Report**, choose the reporting month and PDF format. The report uses the application timezone, clips open incidents at the reporting-period end, includes a deterministic summary/recommendations and displays the previous month only when a snapshot exists. Institution name and optional public logo URL are managed from Institution settings.

At-risk and breached SLA snapshots are sent through the existing notification engine when a service and channel are enabled. Delivery keys are unique per service, period, event and channel, so repeated SLA calculations do not duplicate alerts.

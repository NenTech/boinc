<?
require_once("docutil.php");
page_head("Scheduler RPC timing and retry policies");
echo "
<p>
Each scheduler RPC reports results, gets work, or both.
The client's <b>scheduler RPC policy</b> has several components:
when to make a scheduler RPC, which project to contact,
which scheduling server for
that project, how much work to ask for, and what to do if the RPC fails.
<p>
The scheduler RPC policy has the following goals:
<ul>
<li> Make as few scheduler RPCs as possible.
<li> Use random exponential backoff
if a project's scheduling servers are down
(i.e. delay by a random number times 2^N,
where N is the number of unsuccessful attempts).
This avoids an RPC storm when the servers come back up.
<li> Eventually re-read a project's master URL file in case its set
of schedulers changes.
<li> Report results before or soon after their deadlines.
</ul>
<h3>Resource debt</h3> 
<p>
The client maintains an exponentially-averaged sum of the CPU time
it has devoted to each project.
The constant EXP_DECAY_RATE determines
the decay rate (currently a factor of e every week).
<p>
Each project is assigned a <b>resource debt</b>, computed as
<p>
resource_debt = resource_share / exp_avg_cpu
<p>
where 'exp_avg_cpu' is the CPU time used recently by the project
(exponentially averaged).
Resource debt is a measure of how much work the client owes the
project, and in general the project with the greatest resource debt is
the one from which work should be requested. 

<h3>Minimum RPC time</h3> 
<p>
The client maintains a <b>minimum RPC time</b> for each project.
This is the earliest time at which a scheduling RPC should be done to
that project (if zero, an RPC can be done immediately).
The minimum RPC time can be set for various reasons: 
<ul>
<li> Because of a request from the project, i.e. a
&lt;request_delay&gt; element in a scheduler reply message.
<li> Because RPCs to all of the project's scheduler have failed.
An exponential backoff policy is used.
<li> Because one of the project's computations has failed (the
application crashed, or a file upload or download failed).
An exponential backoff policy is used to prevent a cycle of rapid failures.
</ul>

<h3>Scheduler RPC sessions</h3> 
<p>
Communication with schedulers is organized into <b>sessions</b>,
each of which may involve many RPCs.
There are two types of sessions:
</p>
<ul>
<li> <b>Get-work</b> sessions, whose goal is to get a certain amount of work.
Results may be reported as a side-effect.
<li>
<b>Report-result</b> sessions, whose goal is to report results.
Work may be fetched as a side-effect.
</ul>
The internal logic of scheduler sessions is encapsulated in the class
SCHEDULER_OP.
This is implemented as a state machine, but its logic
expressed as a process might look like:
<pre>
get_work_session() {
    while estimated work &lt; high water mark
        P = project with greatest debt and min_rpc_time &lt; now
        for each scheduler URL of P
            attempt an RPC to that URL
            if no error break
        if some RPC succeeded
            P.nrpc_failures = 0
        else
            P.nrpc_failures++
            P.min_rpc_time = exponential_backoff(P.min_rpc_failures)
            if P.nrpc_failures mod MASTER_FETCH_PERIOD = 0
                P.fetch_master_flag = true
    for each project P with P.fetch_master_flag set
        read and parse master file
        if error
            P.nrpc_failures++
            P.min_rpc_time = exponential_backoff(P.min_rpc_failures)
        if got any new scheduler urls
            P.nrpc_failures = 0
            P.min_rpc_time = 0
}

report_result_session(project P) {
    for each scheduler URL of project
        attempt an RPC to that URL
        if no error break
    if some RPC succeeded
        P.nrpc_failures = 0
    else
        P.nrpc_failures++;
        P.min_rpc_time = exponential_backoff(P.min_rpc_failures)
}
</pre>
The logic for initiating scheduler sessions is embodied in the
<a href=client_logic.php>scheduler_rpcs->poll()</a> function.
<pre>
if a scheduler RPC session is not active
    if estimated work is less than low-water mark
        start a get-work session
    else if some project P has overdue results
        start a report-result session for P;
        if P is the project with greatest resource debt,
        the RPC request should ask for enough work to bring us up
        to the high-water mark
</pre> 
";
page_tail();
?>

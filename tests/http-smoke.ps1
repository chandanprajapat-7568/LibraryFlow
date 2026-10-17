param(
    [string] $BaseUrl = 'http://127.0.0.1:8097'
)

$ErrorActionPreference = 'Stop'
Import-Module Microsoft.PowerShell.Utility
$baseUri = [uri] $BaseUrl
if ($baseUri.Scheme -ne 'http' -or $baseUri.Host -notin @('127.0.0.1', 'localhost', '::1')) {
    throw 'Smoke tests are restricted to an HTTP loopback address. Do not point them at a live site.'
}
$script:passed = 0
$script:baseUrl = $BaseUrl.TrimEnd('/')

function Assert-Condition([bool] $Condition, [string] $Message) {
    if (-not $Condition) {
        throw "FAIL: $Message"
    }
    $script:passed++
    Write-Output "PASS: $Message"
}

function New-WebSession {
    return [Microsoft.PowerShell.Commands.WebRequestSession]::new()
}

function Invoke-App([string] $Path, [Microsoft.PowerShell.Commands.WebRequestSession] $Session, [string] $Method = 'GET', [hashtable] $Body = $null) {
    $parameters = @{
        Uri = $script:baseUrl + $Path
        Method = $Method
        WebSession = $Session
        MaximumRedirection = 5
        TimeoutSec = 15
        SkipHttpErrorCheck = $true
    }
    if ($null -ne $Body) {
        $parameters.Body = $Body
        $parameters.ContentType = 'application/x-www-form-urlencoded'
    }
    return Invoke-WebRequest @parameters
}

function Get-Csrf([string] $Html) {
    $match = [regex]::Match($Html, 'name="csrf_token"\s+value="([^"]+)"')
    if (-not $match.Success) {
        throw 'CSRF field was not present on the expected form.'
    }
    return $match.Groups[1].Value
}

function Get-CookieValue([Microsoft.PowerShell.Commands.WebRequestSession] $Session, [string] $Name) {
    $cookie = $Session.Cookies.GetCookies($baseUri) | Where-Object Name -eq $Name | Select-Object -First 1
    if ($cookie) {
        return $cookie.Value
    }
    return ''
}

$guest = New-WebSession
$unauthenticated = Invoke-App '/index.php' $guest
Assert-Condition ($unauthenticated.Content.Contains('Sign in') -and -not $unauthenticated.Content.Contains('Library overview')) 'unauthenticated dashboard redirects to sign in'
$privacy = Invoke-App '/privacy.php' $guest
Assert-Condition ($privacy.StatusCode -eq 200 -and $privacy.Content.Contains('Privacy overview')) 'privacy page is public and responds'
$terms = Invoke-App '/terms.php' $guest
Assert-Condition ($terms.StatusCode -eq 200 -and $terms.Content.Contains('Terms of use')) 'terms page is public and responds'

$loginPage = Invoke-App '/login.php' $guest
$csrf = Get-Csrf $loginPage.Content
Assert-Condition ($loginPage.Content.Contains('Forgot password?') -and $loginPage.Content.Contains('Administrator sign in') -and -not $loginPage.Content.Contains('data-password-rules')) 'staff sign-in offers recovery and administrator access without new-password rules'
$adminLoginPage = Invoke-App '/admin-login.php' $guest
Assert-Condition ($adminLoginPage.StatusCode -eq 200 -and $adminLoginPage.Content.Contains('Administrator access') -and $adminLoginPage.Content.Contains('Staff sign in')) 'separate administrator sign-in page is public and links back to staff access'
$clientPasswordScript = Invoke-App '/assets/js/app.js' $guest
Assert-Condition ($clientPasswordScript.Content.Contains('password-visibility-toggle') -and $clientPasswordScript.Content.Contains("toggle.textContent = showing ? 'Hide' : 'Show'")) 'password fields include an accessible show/hide control'
for ($i = 1; $i -le 6; $i++) {
    $failed = Invoke-App '/login.php' $guest 'POST' @{
        csrf_token = $csrf
        email = 'missing@example.test'
        password = 'Incorrect synthetic password'
    }
}
Assert-Condition ($failed.Content.Contains('Too many attempts')) 'repeated failed sign-ins trigger temporary throttling'

$adminSession = New-WebSession
$setupPage = Invoke-App '/setup.php' $adminSession
Assert-Condition ($setupPage.StatusCode -eq 200 -and $setupPage.Content.Contains('Create the first administrator')) 'first-account setup is available on loopback'
Assert-Condition ($setupPage.Content.Contains('data-password-rules') -and $setupPage.Content.Contains('At least 8 characters') -and $setupPage.Content.Contains('One lowercase letter') -and $setupPage.Content.Contains('One uppercase letter') -and $setupPage.Content.Contains('One special character')) 'new administrator password shows the live password checklist'
$adminPassword = 'SmokeAdmin#' + [guid]::NewGuid().ToString('N').Substring(0, 10)
$setupCsrf = Get-Csrf $setupPage.Content
$invalidSetup = Invoke-App '/setup.php' $adminSession 'POST' @{
    csrf_token = $setupCsrf
    display_name = 'Invalid Administrator'
    email = 'invalid-admin@example.test'
    password = 'lowercase#only'
    confirm_password = 'lowercase#only'
}
Assert-Condition ($invalidSetup.Content.Contains('one uppercase letter')) 'administrator password without uppercase is rejected'
$setupPage = Invoke-App '/setup.php' $adminSession
$setupCsrf = Get-Csrf $setupPage.Content
$setupResult = Invoke-App '/setup.php' $adminSession 'POST' @{
    csrf_token = $setupCsrf
    display_name = 'Smoke Administrator'
    email = 'admin@example.test'
    password = $adminPassword
    confirm_password = $adminPassword
}
Assert-Condition ($setupResult.StatusCode -eq 200 -and $setupResult.Content.Contains('Sign in')) 'first administrator is created without a default password'

$wrongAdministratorPortal = Invoke-App '/login.php' $adminSession 'POST' @{
    csrf_token = (Get-Csrf (Invoke-App '/login.php' $adminSession).Content)
    email = 'admin@example.test'
    password = $adminPassword
}
Assert-Condition ($wrongAdministratorPortal.Content.Contains('Email or password is incorrect') -and -not $wrongAdministratorPortal.Content.Contains('Library overview')) 'administrator account cannot sign in through the staff portal'
$adminLoginPage = Invoke-App '/admin-login.php' $adminSession
$loginCsrf = Get-Csrf $adminLoginPage.Content
$sessionBeforeLogin = Get-CookieValue $adminSession 'PHPSESSID'
$signedIn = Invoke-App '/admin-login.php' $adminSession 'POST' @{
    csrf_token = $loginCsrf
    email = 'admin@example.test'
    password = $adminPassword
}
$sessionAfterLogin = Get-CookieValue $adminSession 'PHPSESSID'
Assert-Condition ($signedIn.Content.Contains('Library overview')) 'administrator signs in through the administrator portal'
$primaryNav = [regex]::Match($signedIn.Content, '(?s)<nav class="primary-nav">(.*?)</nav>').Groups[1].Value
Assert-Condition ($primaryNav.IndexOf('>Search</a>') -lt $primaryNav.IndexOf('>Dashboard</a>') -and $primaryNav.Contains('Change password') -and -not $signedIn.Content.Contains('topbar-note">Password')) 'search is first in the navigation and password settings are in the sidebar'
Assert-Condition (-not $loginPage.Content.Contains('password-rules') -and -not $loginPage.Content.Contains('data-password-rules')) 'password rules are hidden from the sign-in form'
Assert-Condition ($sessionBeforeLogin -ne $sessionAfterLogin) 'session ID rotates after sign-in'
Assert-Condition (-not [string]::IsNullOrEmpty(($signedIn.Headers['Content-Security-Policy'] -join ''))) 'security response headers are present'

$protectedPages = @(
    '/index.php',
    '/books/index.php',
    '/members/index.php',
    '/issues/index.php',
    '/search/index.php',
    '/reports/index.php',
    '/account/password.php',
    '/admin/users.php'
)
foreach ($path in $protectedPages) {
    $page = Invoke-App $path $adminSession
    Assert-Condition ($page.StatusCode -eq 200) "authorized page loads: $path"
}

$invalidBookForm = Invoke-App '/books/form.php' $adminSession
$invalidBookCsrf = Get-Csrf $invalidBookForm.Content
$invalidBookResponse = Invoke-App '/books/save.php' $adminSession 'POST' @{
    csrf_token = $invalidBookCsrf
    book_id = ''
    title = ''
    author = ''
    isbn = ''
    publisher = ''
    category = ''
    publication_year = ''
    total_copies = '1'
}
Assert-Condition ($invalidBookResponse.Content.Contains('Enter a book title')) 'blank book title is rejected'

$blankAuthorForm = Invoke-App '/books/form.php' $adminSession
$blankAuthorCsrf = Get-Csrf $blankAuthorForm.Content
$blankAuthorResponse = Invoke-App '/books/save.php' $adminSession 'POST' @{
    csrf_token = $blankAuthorCsrf
    book_id = ''
    title = 'Book Without an Author'
    author = ''
    isbn = ''
    publisher = ''
    category = ''
    publication_year = ''
    total_copies = '1'
}
Assert-Condition ($blankAuthorResponse.Content.Contains('Enter an author')) 'blank book author is rejected'

$bookForm = Invoke-App '/books/form.php' $adminSession
$bookCsrf = Get-Csrf $bookForm.Content
$bookResponse = Invoke-App '/books/save.php' $adminSession 'POST' @{
    csrf_token = $bookCsrf
    book_id = ''
    title = 'Smoke Test Catalogue Book'
    author = 'Synthetic Author'
    isbn = '9781234567890'
    publisher = 'Test Press'
    category = 'Testing'
    publication_year = '2020'
    total_copies = '2'
}
$bookList = Invoke-App '/books/index.php?q=Smoke%20Test' $adminSession
$bookIdMatch = [regex]::Match($bookList.Content, 'cell-sub">ID\s+(\d+)')
Assert-Condition ($bookResponse.StatusCode -eq 200 -and $bookIdMatch.Success) 'valid book creation and search work'
$bookId = $bookIdMatch.Groups[1].Value

$editBookForm = Invoke-App ("/books/form.php?id=$bookId") $adminSession
$editBookCsrf = Get-Csrf $editBookForm.Content
$editBookResponse = Invoke-App '/books/save.php' $adminSession 'POST' @{
    csrf_token = $editBookCsrf
    book_id = $bookId
    title = 'Smoke Test Catalogue Book Updated'
    author = 'Synthetic Author'
    isbn = '9781234567890'
    publisher = 'Test Press'
    category = 'Testing'
    publication_year = '2020'
    total_copies = '2'
}
$updatedBookList = Invoke-App '/books/index.php?q=Updated' $adminSession
Assert-Condition ($editBookResponse.StatusCode -eq 200 -and $updatedBookList.Content.Contains('Smoke Test Catalogue Book Updated')) 'book update is saved'
$bookSearchTitle = Invoke-App '/search/index.php?q=Catalogue&type=books' $adminSession
$bookSearchAuthor = Invoke-App '/search/index.php?q=Synthetic%20Author&type=books' $adminSession
$bookSearchId = Invoke-App ("/search/index.php?q=$bookId&type=books") $adminSession
$bookSearchMiss = Invoke-App '/search/index.php?q=NoSuchSmokeTitle&type=books' $adminSession
Assert-Condition ($bookSearchTitle.Content.Contains('Smoke Test Catalogue Book Updated')) 'book search matches a partial title'
Assert-Condition ($bookSearchAuthor.Content.Contains('Synthetic Author')) 'book search matches an author'
Assert-Condition ($bookSearchId.Content.Contains("ID $bookId")) 'book search matches the numeric book ID'
Assert-Condition ($bookSearchMiss.Content.Contains('No matching records')) 'book search presents a no-result state'

$duplicateBookForm = Invoke-App '/books/form.php' $adminSession
$duplicateCsrf = Get-Csrf $duplicateBookForm.Content
$duplicateBook = Invoke-App '/books/save.php' $adminSession 'POST' @{
    csrf_token = $duplicateCsrf
    book_id = ''
    title = 'Duplicate ISBN Test'
    author = 'Synthetic Author'
    isbn = '9781234567890'
    publisher = ''
    category = ''
    publication_year = ''
    total_copies = '1'
}
Assert-Condition ($duplicateBook.Content.Contains('already assigned')) 'duplicate ISBN is rejected'

$memberName = 'Smoke <script>alert(1)</script>'
$memberForm = Invoke-App '/members/form.php' $adminSession
$memberCsrf = Get-Csrf $memberForm.Content
Assert-Condition ($memberForm.Content.Contains('BSc - CBZ (Chemistry, Botany, Zoology)') -and $memberForm.Content.Contains('value="BCA"') -and $memberForm.Content.Contains('data-max-semesters="6"')) 'member course selector lists college programs with semester limits'
$invalidMemberSemester = Invoke-App '/members/save.php' $adminSession 'POST' @{
    csrf_token = $memberCsrf
    member_id = ''
    member_code = 'INVALIDSEM'
    full_name = 'Invalid Semester Test'
    course = 'BCA'
    section_group = ''
    semester = '7'
    phone = ''
    email = ''
}
Assert-Condition ($invalidMemberSemester.Content.Contains('maximum of 6 semesters')) 'server rejects semester values above the selected program duration'
$memberResponse = Invoke-App '/members/save.php' $adminSession 'POST' @{
    csrf_token = $memberCsrf
    member_id = ''
    member_code = 'SMOKE001'
    full_name = $memberName
    course = 'BCA'
    section_group = 'Section A, Group A1'
    semester = '5'
    phone = ''
    email = ''
}
$memberList = Invoke-App '/members/index.php?q=SMOKE001' $adminSession
$memberIdMatch = [regex]::Match($memberList.Content, 'SMOKE001\s+·\s+ID\s+(\d+)')
Assert-Condition ($memberResponse.StatusCode -eq 200 -and $memberIdMatch.Success) 'member creation and code search work'
Assert-Condition ($memberList.Content.Contains('Section A, Group A1')) 'member register retains the section and group alongside the selected program'
$memberId = $memberIdMatch.Groups[1].Value
Assert-Condition ($memberList.Content.Contains('&lt;script&gt;alert(1)&lt;/script&gt;') -and -not $memberList.Content.Contains('<script>alert(1)</script>')) 'member text is HTML-escaped'
$memberNameSearch = Invoke-App '/search/index.php?q=Smoke&type=members' $adminSession
Assert-Condition ($memberNameSearch.Content.Contains('SMOKE001')) 'member search matches a partial name'

$secondBookForm = Invoke-App '/books/form.php' $adminSession
$secondBookCsrf = Get-Csrf $secondBookForm.Content
$secondBookResult = Invoke-App '/books/save.php' $adminSession 'POST' @{
    csrf_token = $secondBookCsrf
    book_id = ''
    title = 'Smoke Test Deletable Book'
    author = 'Synthetic Author'
    isbn = '9781234567807'
    publisher = ''
    category = ''
    publication_year = ''
    total_copies = '1'
}
$secondBookList = Invoke-App '/books/index.php?q=Deletable' $adminSession
$secondBookId = [regex]::Match($secondBookList.Content, 'cell-sub">ID\s+(\d+)').Groups[1].Value
Assert-Condition ($secondBookResult.StatusCode -eq 200 -and $secondBookId) 'second synthetic book is created for delete coverage'

$secondMemberForm = Invoke-App '/members/form.php' $adminSession
$secondMemberCsrf = Get-Csrf $secondMemberForm.Content
$secondMemberResult = Invoke-App '/members/save.php' $adminSession 'POST' @{
    csrf_token = $secondMemberCsrf
    member_id = ''
    member_code = 'SMOKE002'
    full_name = 'Smoke Delete Target'
    course = 'BCA'
    section_group = ''
    semester = '5'
    phone = ''
    email = ''
}
$secondMemberList = Invoke-App '/members/index.php?q=SMOKE002' $adminSession
$secondMemberId = [regex]::Match($secondMemberList.Content, 'SMOKE002\s+·\s+ID\s+(\d+)').Groups[1].Value
Assert-Condition ($secondMemberResult.StatusCode -eq 200 -and $secondMemberId) 'second synthetic member is created for delete coverage'

$librarianPassword = 'SmokeLibrarian#' + [guid]::NewGuid().ToString('N').Substring(0, 10)
$usersPage = Invoke-App '/admin/users.php' $adminSession
$usersCsrf = Get-Csrf $usersPage.Content
$staffResult = Invoke-App '/admin/users.php' $adminSession 'POST' @{
    csrf_token = $usersCsrf
    action = 'create'
    display_name = 'Smoke Librarian'
    email = 'librarian@example.test'
    role = 'librarian'
    password = $librarianPassword
}
Assert-Condition ($staffResult.StatusCode -eq 200 -and $staffResult.Content.Contains('Smoke Librarian')) 'administrator can create a librarian account'
$librarianOnAdminPortal = New-WebSession
$librarianAdminPage = Invoke-App '/admin-login.php' $librarianOnAdminPortal
$librarianAdminAttempt = Invoke-App '/admin-login.php' $librarianOnAdminPortal 'POST' @{
    csrf_token = (Get-Csrf $librarianAdminPage.Content)
    email = 'librarian@example.test'
    password = $librarianPassword
}
Assert-Condition ($librarianAdminAttempt.Content.Contains('Email or password is incorrect') -and -not $librarianAdminAttempt.Content.Contains('Library overview')) 'librarian account cannot sign in through the administrator portal'
$usersForAdminCheck = Invoke-App '/admin/users.php' $adminSession
$adminRow = [regex]::Match($usersForAdminCheck.Content, '(?s)<tr>.*?admin@example\.test.*?</tr>').Value
$firstAdminId = [regex]::Match($adminRow, 'Protected administrator account').Success
$librarianId = [regex]::Match($usersForAdminCheck.Content, 'admin/password\.php\?id=(\d+)').Groups[1].Value
Assert-Condition ($firstAdminId -and $librarianId -and -not $adminRow.Contains('name="action"')) 'administrator account is listed without staff-management actions'
$adminActionCsrf = Get-Csrf $usersForAdminCheck.Content
$protectedAdminDelete = Invoke-App '/admin/users.php' $adminSession 'POST' @{ csrf_token = $adminActionCsrf; action = 'delete'; user_id = '1' }
Assert-Condition ($protectedAdminDelete.Content.Contains('Administrator accounts are protected')) 'administrator cannot be permanently removed through staff actions'
$protectedAdminToggle = Invoke-App '/admin/users.php' $adminSession 'POST' @{ csrf_token = (Get-Csrf $protectedAdminDelete.Content); action = 'toggle'; user_id = '1' }
Assert-Condition ($protectedAdminToggle.Content.Contains('Administrator accounts are protected')) 'administrator cannot be deactivated through staff actions'
$protectedAdminPassword = Invoke-App '/admin/password.php?id=1' $adminSession
Assert-Condition ($protectedAdminPassword.StatusCode -eq 404) 'administrator password cannot be reset from staff management'
$invalidStaffPassword = Invoke-App '/admin/users.php' $adminSession 'POST' @{
    csrf_token = (Get-Csrf (Invoke-App '/admin/users.php' $adminSession).Content)
    action = 'create'
    display_name = 'Invalid Librarian'
    email = 'invalid-librarian@example.test'
    password = 'lowercase#only'
}
Assert-Condition ($invalidStaffPassword.Content.Contains('one uppercase letter')) 'librarian password without uppercase is rejected'

$librarianSession = New-WebSession
$librarianLogin = Invoke-App '/login.php' $librarianSession
$librarianCsrf = Get-Csrf $librarianLogin.Content
$librarianSignedIn = Invoke-App '/login.php' $librarianSession 'POST' @{
    csrf_token = $librarianCsrf
    email = 'librarian@example.test'
    password = $librarianPassword
}
Assert-Condition ($librarianSignedIn.Content.Contains('Library overview')) 'librarian sign-in succeeds'
$parallelSession = New-WebSession
$parallelLoginPage = Invoke-App '/login.php' $parallelSession
$parallelCsrf = Get-Csrf $parallelLoginPage.Content
$parallelLogin = Invoke-App '/login.php' $parallelSession 'POST' @{
    csrf_token = $parallelCsrf
    email = 'librarian@example.test'
    password = $librarianPassword
}
Assert-Condition ($parallelLogin.Content.Contains('Library overview')) 'second librarian session can sign in'
$adminOnly = Invoke-App '/admin/users.php' $librarianSession
Assert-Condition ($adminOnly.StatusCode -eq 403) 'librarian is denied staff-management access'
$staffPasswordDenied = Invoke-App ("/admin/password.php?id=$librarianId") $librarianSession
Assert-Condition ($staffPasswordDenied.StatusCode -eq 403) 'librarian cannot use administrator password reset'
$librarianDeletePage = Invoke-App ("/books/delete.php?id=$secondBookId") $librarianSession
Assert-Condition ($librarianDeletePage.StatusCode -eq 200 -and $librarianDeletePage.Content.Contains('Delete book record')) 'librarian can open a book delete confirmation'
$librarianBookList = Invoke-App '/books/index.php?q=Smoke%20Test' $librarianSession
$librarianMemberList = Invoke-App '/members/index.php?q=SMOKE002' $librarianSession
Assert-Condition ($librarianBookList.Content.Contains('class="actions-cell"><div class="row-actions">') -and $librarianMemberList.Content.Contains('class="actions-cell"><div class="row-actions">') -and $librarianBookList.Content.Contains('>Delete</a>') -and $librarianMemberList.Content.Contains('>Delete</a>')) 'librarian sees aligned delete actions for books and members'

$issueForm = Invoke-App '/issues/create.php' $librarianSession
$issueCsrf = Get-Csrf $issueForm.Content
$bookOption = [regex]::Match($issueForm.Content, '<select id="book_id".*?<option value="(\d+)"', [System.Text.RegularExpressions.RegexOptions]::Singleline).Groups[1].Value
$memberOption = [regex]::Match($issueForm.Content, '<select id="member_id".*?<option value="(\d+)"', [System.Text.RegularExpressions.RegexOptions]::Singleline).Groups[1].Value
Assert-Condition ($bookOption -eq $bookId -and $memberOption -eq $memberId) 'issue form offers the selected test book and member'
$issueDate = (Get-Date).ToString('yyyy-MM-dd')
$dueDate = (Get-Date).AddDays(14).ToString('yyyy-MM-dd')
$invalidDate = (Get-Date).AddDays(-1).ToString('yyyy-MM-dd')
$invalidIssue = Invoke-App '/issues/create.php' $librarianSession 'POST' @{
    csrf_token = $issueCsrf
    book_id = $bookId
    member_id = $secondMemberId
    issue_date = $issueDate
    due_date = $invalidDate
}
Assert-Condition ($invalidIssue.Content.Contains('Due date must be on or after the issue date')) 'invalid due date is rejected'

for ($i = 1; $i -le 2; $i++) {
    $issueForm = Invoke-App '/issues/create.php' $librarianSession
    $issueCsrf = Get-Csrf $issueForm.Content
    $loanIssueDate = $issueDate
    $loanDueDate = $dueDate
    if ($i -eq 2) {
        $loanIssueDate = (Get-Date).AddDays(-45).ToString('yyyy-MM-dd')
        $loanDueDate = (Get-Date).AddDays(-31).ToString('yyyy-MM-dd')
    }
    $issueResult = Invoke-App '/issues/create.php' $librarianSession 'POST' @{
        csrf_token = $issueCsrf
        book_id = $bookId
        member_id = $memberId
        issue_date = $loanIssueDate
        due_date = $loanDueDate
    }
    Assert-Condition ($issueResult.StatusCode -eq 200) "loan $i is recorded"
}
$openIssuesBeforeReturn = Invoke-App '/issues/index.php?status=open' $librarianSession
$overdueIssues = Invoke-App '/issues/index.php?status=overdue' $librarianSession
$overdueReport = Invoke-App '/reports/index.php?status=overdue' $librarianSession
Assert-Condition ($openIssuesBeforeReturn.Content.Contains('2 records')) 'open issue filter returns both active loans'
Assert-Condition ($overdueIssues.Content.Contains('Overdue') -and $overdueIssues.Content.Contains('Smoke Test Catalogue Book Updated')) 'issue register filters overdue loans'
Assert-Condition ($overdueReport.Content.Contains('Overdue') -and $overdueReport.Content.Contains('Smoke Test Catalogue Book Updated')) 'reports filter overdue loans'
$noCopyForm = Invoke-App '/issues/create.php' $librarianSession
$noCopyCsrf = Get-Csrf $noCopyForm.Content
$noCopyResult = Invoke-App '/issues/create.php' $librarianSession 'POST' @{
    csrf_token = $noCopyCsrf
    book_id = $bookId
    member_id = $secondMemberId
    issue_date = $issueDate
    due_date = $dueDate
}
Assert-Condition ($noCopyResult.Content.Contains('No copies of this title are currently available')) 'issue is blocked when every copy is loaned'

$issueList = Invoke-App '/issues/index.php' $librarianSession
$issueId = [regex]::Match($issueList.Content, 'name="issue_id"\s+value="(\d+)"').Groups[1].Value
$returnCsrf = Get-Csrf $issueList.Content
$returned = Invoke-App '/issues/return.php' $librarianSession 'POST' @{ csrf_token = $returnCsrf; issue_id = $issueId }
Assert-Condition ($returned.StatusCode -eq 200 -and $returned.Content.Contains('Late return recorded') -and $returned.Content.Contains('cannot borrow another book until')) 'late return starts a 10-day borrowing hold'
$returnedAgain = Invoke-App '/issues/return.php' $librarianSession 'POST' @{ csrf_token = $returnCsrf; issue_id = $issueId }
Assert-Condition ($returnedAgain.Content.Contains('already been returned')) 'duplicate return is rejected'
$heldIssueForm = Invoke-App '/issues/create.php' $librarianSession
$heldIssueCsrf = Get-Csrf $heldIssueForm.Content
$heldIssue = Invoke-App '/issues/create.php' $librarianSession 'POST' @{
    csrf_token = $heldIssueCsrf
    book_id = $bookId
    member_id = $memberId
    issue_date = $issueDate
    due_date = $dueDate
}
Assert-Condition ($heldIssue.Content.Contains('Borrowing is suspended until') -and $heldIssue.Content.Contains('returned late')) 'member cannot reborrow during the 10-day hold'
$memberHold = Invoke-App '/members/index.php?q=SMOKE001' $librarianSession
Assert-Condition ($memberHold.Content.Contains('Borrowing hold') -and $memberHold.Content.Contains('Until')) 'member register displays the hold end date'
$openIssuesAfterReturn = Invoke-App '/issues/index.php?status=open' $librarianSession
$returnedIssues = Invoke-App '/issues/index.php?status=returned' $librarianSession
Assert-Condition ($openIssuesAfterReturn.Content.Contains('1 record')) 'open filter excludes the returned loan'
Assert-Condition ($returnedIssues.Content.Contains('Returned') -and $returnedIssues.Content.Contains('1 record')) 'returned filter shows the completed loan'

$reports = Invoke-App '/reports/index.php?status=open' $librarianSession
Assert-Condition ($reports.StatusCode -eq 200 -and $reports.Content.Contains('Open')) 'open-loan report filter loads'
$search = Invoke-App '/search/index.php?q=SMOKE001&type=members' $librarianSession
Assert-Condition ($search.StatusCode -eq 200 -and $search.Content.Contains('SMOKE001')) 'combined search finds the member code'
$sqlInjectionSearch = Invoke-App '/search/index.php?q=%27%20OR%201%3D1--&type=members' $librarianSession
Assert-Condition ($sqlInjectionSearch.StatusCode -eq 200 -and $sqlInjectionSearch.Content.Contains('No matching records')) 'SQL-like search text is handled as a value'

$blockedBookDelete = Invoke-App ("/books/delete.php?id=$bookId") $adminSession
Assert-Condition ($blockedBookDelete.Content.Contains('has lending history')) 'book with loan history cannot be deleted'
$blockedMemberDelete = Invoke-App ("/members/delete.php?id=$memberId") $adminSession
Assert-Condition ($blockedMemberDelete.Content.Contains('has lending history')) 'member with loan history cannot be deleted'

$deleteBookPage = Invoke-App ("/books/delete.php?id=$secondBookId") $librarianSession
$deleteBookCsrf = Get-Csrf $deleteBookPage.Content
$deletedBook = Invoke-App '/books/delete.php' $librarianSession 'POST' @{ csrf_token = $deleteBookCsrf; book_id = $secondBookId }
Assert-Condition ($deletedBook.StatusCode -eq 200 -and -not $deletedBook.Content.Contains('Smoke Test Deletable Book')) 'librarian can delete an unreferenced book with POST and CSRF'
$deleteMemberPage = Invoke-App ("/members/delete.php?id=$secondMemberId") $librarianSession
$deleteMemberCsrf = Get-Csrf $deleteMemberPage.Content
$deletedMember = Invoke-App '/members/delete.php' $librarianSession 'POST' @{ csrf_token = $deleteMemberCsrf; member_id = $secondMemberId }
Assert-Condition ($deletedMember.StatusCode -eq 200 -and -not $deletedMember.Content.Contains('Smoke Delete Target')) 'librarian can delete an unreferenced member with POST and CSRF'
$dashboard = Invoke-App '/index.php' $adminSession
$reconciledReport = Invoke-App '/reports/index.php' $adminSession
$dashboardTotals = @{
    'Book titles' = '1'
    'Total copies' = '2'
    'Issued copies' = '1'
    'Available copies' = '1'
    'Members' = '1'
    'Overdue loans' = '0'
}
$reportTotals = @{
    'Book titles' = '1'
    'Total copies' = '2'
    'Issued' = '1'
    'Available' = '1'
    'Members' = '1'
    'Overdue loans' = '0'
}
$dashboardConsistent = $true
foreach ($entry in $dashboardTotals.GetEnumerator()) {
    $statPattern = '<span class="stat-label">' + [regex]::Escape($entry.Key) + '</span>\s*<strong>' + [regex]::Escape($entry.Value) + '</strong>'
    if (-not [regex]::IsMatch($dashboard.Content, $statPattern)) { $dashboardConsistent = $false }
}
$reportConsistent = $true
foreach ($entry in $reportTotals.GetEnumerator()) {
    $statPattern = '<span class="stat-label">' + [regex]::Escape($entry.Key) + '</span>\s*<strong>' + [regex]::Escape($entry.Value) + '</strong>'
    if (-not [regex]::IsMatch($reconciledReport.Content, $statPattern)) { $reportConsistent = $false }
}
Assert-Condition ($dashboardConsistent -and $reportConsistent -and $reconciledReport.Content.Contains('Smoke Test Catalogue Book Updated')) 'dashboard, report and remaining inventory totals reconcile'

$invalidCsrf = Invoke-App '/logout.php' $librarianSession 'POST' @{ csrf_token = 'invalid'; }
Assert-Condition ($invalidCsrf.StatusCode -eq 403) 'invalid CSRF token is rejected'
$changePendingSession = New-WebSession
$changePendingPage = Invoke-App '/forgot-password.php' $changePendingSession
$changePendingResult = Invoke-App '/forgot-password.php' $changePendingSession 'POST' @{
    csrf_token = (Get-Csrf $changePendingPage.Content)
    email = 'librarian@example.test'
}
$changePendingToken = [regex]::Match($changePendingResult.Content, 'data-test-reset-token="([a-f0-9]{64})"').Groups[1].Value
Assert-Condition ($changePendingToken -ne '') 'self-service password change starts with a pending recovery token'
$passwordPage = Invoke-App '/account/password.php' $librarianSession
$passwordCsrf = Get-Csrf $passwordPage.Content
$newLibrarianPassword = 'SmokeChanged#' + [guid]::NewGuid().ToString('N').Substring(0, 10)
$passwordChange = Invoke-App '/account/password.php' $librarianSession 'POST' @{
    csrf_token = $passwordCsrf
    current_password = $librarianPassword
    new_password = $newLibrarianPassword
    confirm_password = $newLibrarianPassword
}
Assert-Condition ($passwordChange.StatusCode -eq 200 -and $passwordChange.Content.Contains('password has been changed')) 'staff member can change their password'
$revokedChangeLink = Invoke-App ("/reset-password.php?token=$changePendingToken") $changePendingSession
Assert-Condition ($revokedChangeLink.Content.Contains('invalid or has expired')) 'self-service password change revokes pending email recovery links'
$revokedSession = Invoke-App '/index.php' $parallelSession
Assert-Condition ($revokedSession.Content.Contains('Sign in') -and -not $revokedSession.Content.Contains('Library overview')) 'password change invalidates other active sessions'
$logoutPage = Invoke-App '/account/password.php' $librarianSession
$logoutCsrf = Get-Csrf $logoutPage.Content
$logoutResult = Invoke-App '/logout.php' $librarianSession 'POST' @{ csrf_token = $logoutCsrf }
$afterLogout = Invoke-App '/index.php' $librarianSession
Assert-Condition ($afterLogout.Content.Contains('Sign in') -and -not $afterLogout.Content.Contains('Library overview')) 'sign-out ends the authenticated session'

$oldPasswordSession = New-WebSession
$oldPasswordLogin = Invoke-App '/login.php' $oldPasswordSession
$oldPasswordCsrf = Get-Csrf $oldPasswordLogin.Content
$oldPasswordResult = Invoke-App '/login.php' $oldPasswordSession 'POST' @{
    csrf_token = $oldPasswordCsrf
    email = 'librarian@example.test'
    password = $librarianPassword
}
Assert-Condition ($oldPasswordResult.Content.Contains('Email or password is incorrect')) 'old password stops working after password change'
$newPasswordSession = New-WebSession
$newPasswordLogin = Invoke-App '/login.php' $newPasswordSession
$newPasswordCsrf = Get-Csrf $newPasswordLogin.Content
$newPasswordResult = Invoke-App '/login.php' $newPasswordSession 'POST' @{
    csrf_token = $newPasswordCsrf
    email = 'librarian@example.test'
    password = $newLibrarianPassword
}
Assert-Condition ($newPasswordResult.Content.Contains('Library overview')) 'new password signs in successfully'

$pendingResetSession = New-WebSession
$pendingResetPage = Invoke-App '/forgot-password.php' $pendingResetSession
$pendingResetResult = Invoke-App '/forgot-password.php' $pendingResetSession 'POST' @{
    csrf_token = (Get-Csrf $pendingResetPage.Content)
    email = 'librarian@example.test'
}
$pendingResetToken = [regex]::Match($pendingResetResult.Content, 'data-test-reset-token="([a-f0-9]{64})"').Groups[1].Value
Assert-Condition ($pendingResetToken -ne '') 'password reset request creates a pending one-use token'

$adminResetPage = Invoke-App ("/admin/password.php?id=$librarianId") $adminSession
Assert-Condition ($adminResetPage.StatusCode -eq 200 -and $adminResetPage.Content.Contains('data-password-rules')) 'administrator can open librarian password reset with password checklist'
$adminResetCsrf = Get-Csrf $adminResetPage.Content
$invalidAdminReset = Invoke-App ("/admin/password.php?id=$librarianId") $adminSession 'POST' @{
    csrf_token = $adminResetCsrf
    user_id = $librarianId
    new_password = 'NoSpecialChar8A'
    confirm_password = 'NoSpecialChar8A'
}
Assert-Condition ($invalidAdminReset.Content.Contains('one special character')) 'administrator password reset enforces special character'
$adminResetPage = Invoke-App ("/admin/password.php?id=$librarianId") $adminSession
$adminResetCsrf = Get-Csrf $adminResetPage.Content
$resetLibrarianPassword = 'ResetPass#8'
$adminReset = Invoke-App ("/admin/password.php?id=$librarianId") $adminSession 'POST' @{
    csrf_token = $adminResetCsrf
    user_id = $librarianId
    new_password = $resetLibrarianPassword
    confirm_password = $resetLibrarianPassword
}
Assert-Condition ($adminReset.Content.Contains('Password updated')) 'administrator can set a librarian password'
$revokedResetLink = Invoke-App ("/reset-password.php?token=$pendingResetToken") $pendingResetSession
Assert-Condition ($revokedResetLink.Content.Contains('invalid or has expired')) 'administrator password reset revokes pending email recovery links'
$resetRevokedSession = Invoke-App '/index.php' $newPasswordSession
Assert-Condition ($resetRevokedSession.Content.Contains('Sign in') -and -not $resetRevokedSession.Content.Contains('Library overview')) 'administrator password reset invalidates existing librarian sessions'
$oldStaffPasswordSession = New-WebSession
$oldStaffPasswordPage = Invoke-App '/login.php' $oldStaffPasswordSession
$oldStaffPasswordCsrf = Get-Csrf $oldStaffPasswordPage.Content
$oldStaffPasswordLogin = Invoke-App '/login.php' $oldStaffPasswordSession 'POST' @{
    csrf_token = $oldStaffPasswordCsrf
    email = 'librarian@example.test'
    password = $newLibrarianPassword
}
Assert-Condition ($oldStaffPasswordLogin.Content.Contains('Email or password is incorrect')) 'previous librarian password stops working after admin reset'
$resetStaffSession = New-WebSession
$resetStaffLoginPage = Invoke-App '/login.php' $resetStaffSession
$resetStaffCsrf = Get-Csrf $resetStaffLoginPage.Content
$resetStaffLogin = Invoke-App '/login.php' $resetStaffSession 'POST' @{
    csrf_token = $resetStaffCsrf
    email = 'librarian@example.test'
    password = $resetLibrarianPassword
}
Assert-Condition ($resetStaffLogin.Content.Contains('Library overview')) 'librarian can sign in with the admin-set password'

$forgotSession = New-WebSession
$forgotPage = Invoke-App '/forgot-password.php' $forgotSession
$unknownForgotPage = Invoke-App '/forgot-password.php' $forgotSession 'POST' @{
    csrf_token = (Get-Csrf $forgotPage.Content)
    email = 'unknown-reset@example.test'
}
Assert-Condition ($unknownForgotPage.Content.Contains('If an active staff account matches that address') -and -not $unknownForgotPage.Content.Contains('data-test-reset-token')) 'forgot-password response does not reveal unknown accounts'
$forgotSession = New-WebSession
$forgotPage = Invoke-App '/forgot-password.php' $forgotSession
$forgotCsrf = Get-Csrf $forgotPage.Content
$forgotRequest = @{
    csrf_token = $forgotCsrf
    email = 'librarian@example.test'
}
$forgotResult = $null
for ($requestNumber = 1; $requestNumber -le 1; $requestNumber++) {
    $forgotResult = Invoke-App '/forgot-password.php' $forgotSession 'POST' $forgotRequest
}
$resetToken = [regex]::Match($forgotResult.Content, 'data-test-reset-token="([a-f0-9]{64})"').Groups[1].Value
Assert-Condition ($resetToken -ne '' -and $forgotResult.Content.Contains('If an active staff account matches that address')) 'active staff account receives a reset token in isolated test mode'
$rateLimitedReset = Invoke-App '/forgot-password.php' $forgotSession 'POST' $forgotRequest
Assert-Condition (-not $rateLimitedReset.Content.Contains('data-test-reset-token')) 'password reset requests are rate-limited'
$resetLinkPage = Invoke-App ("/reset-password.php?token=$resetToken") $forgotSession
Assert-Condition ($resetLinkPage.StatusCode -eq 200 -and $resetLinkPage.Content.Contains('data-password-rules') -and $resetLinkPage.Content.Contains('type="password"')) 'valid recovery link opens a new-password form with requirements'
$invalidRecoveryPassword = Invoke-App '/reset-password.php' $forgotSession 'POST' @{
    csrf_token = (Get-Csrf $resetLinkPage.Content)
    token = $resetToken
    new_password = 'lowercase#only'
    confirm_password = 'lowercase#only'
}
Assert-Condition ($invalidRecoveryPassword.Content.Contains('one uppercase letter')) 'self-service reset enforces the password checklist server-side'
$resetLinkPage = Invoke-App ("/reset-password.php?token=$resetToken") $forgotSession
$selfServicePassword = 'SelfService#' + [guid]::NewGuid().ToString('N').Substring(0, 10)
$selfServiceReset = Invoke-App '/reset-password.php' $forgotSession 'POST' @{
    csrf_token = (Get-Csrf $resetLinkPage.Content)
    token = $resetToken
    new_password = $selfServicePassword
    confirm_password = $selfServicePassword
}
Assert-Condition ($selfServiceReset.Content.Contains('Password updated') -and $selfServiceReset.Content.Contains('signed out')) 'staff member can complete an email-link password reset'
$reusedResetLink = Invoke-App ("/reset-password.php?token=$resetToken") $forgotSession
Assert-Condition ($reusedResetLink.Content.Contains('invalid or has expired')) 'password reset link cannot be reused'
$resetRevokedSession = Invoke-App '/index.php' $resetStaffSession
Assert-Condition ($resetRevokedSession.Content.Contains('Sign in') -and -not $resetRevokedSession.Content.Contains('Library overview')) 'self-service reset revokes existing sessions'
$oldSelfResetSession = New-WebSession
$oldSelfResetPage = Invoke-App '/login.php' $oldSelfResetSession
$oldSelfResetLogin = Invoke-App '/login.php' $oldSelfResetSession 'POST' @{
    csrf_token = (Get-Csrf $oldSelfResetPage.Content)
    email = 'librarian@example.test'
    password = $resetLibrarianPassword
}
Assert-Condition ($oldSelfResetLogin.Content.Contains('Email or password is incorrect')) 'previous password stops working after self-service reset'
$finalStaffSession = New-WebSession
$finalStaffPage = Invoke-App '/login.php' $finalStaffSession
$finalStaffLogin = Invoke-App '/login.php' $finalStaffSession 'POST' @{
    csrf_token = (Get-Csrf $finalStaffPage.Content)
    email = 'librarian@example.test'
    password = $selfServicePassword
}
Assert-Condition ($finalStaffLogin.Content.Contains('Library overview')) 'staff member can sign in with the recovered password'

$staffListBeforeDeactivate = Invoke-App '/admin/users.php' $adminSession
$staffActionCsrf = Get-Csrf $staffListBeforeDeactivate.Content
$deactivatedStaff = Invoke-App '/admin/users.php' $adminSession 'POST' @{ csrf_token = $staffActionCsrf; action = 'toggle'; user_id = $librarianId }
Assert-Condition ($deactivatedStaff.Content.Contains('Staff account deactivated')) 'administrator can deactivate a librarian'
$deactivatedSession = Invoke-App '/index.php' $finalStaffSession
Assert-Condition ($deactivatedSession.Content.Contains('Sign in') -and -not $deactivatedSession.Content.Contains('Library overview')) 'deactivation signs out active librarian sessions'
$inactiveLoginSession = New-WebSession
$inactiveLoginPage = Invoke-App '/login.php' $inactiveLoginSession
$inactiveLoginCsrf = Get-Csrf $inactiveLoginPage.Content
$inactiveLogin = Invoke-App '/login.php' $inactiveLoginSession 'POST' @{
    csrf_token = $inactiveLoginCsrf
    email = 'librarian@example.test'
    password = $selfServicePassword
}
Assert-Condition ($inactiveLogin.Content.Contains('Email or password is incorrect')) 'deactivated librarian cannot sign in'
$staffListAfterDeactivate = Invoke-App '/admin/users.php' $adminSession
$reactivatedStaff = Invoke-App '/admin/users.php' $adminSession 'POST' @{ csrf_token = (Get-Csrf $staffListAfterDeactivate.Content); action = 'toggle'; user_id = $librarianId }
Assert-Condition ($reactivatedStaff.Content.Contains('Staff account reactivated')) 'administrator can reactivate a librarian'
$staffListBeforeDelete = Invoke-App '/admin/users.php' $adminSession
$deletedStaff = Invoke-App '/admin/users.php' $adminSession 'POST' @{ csrf_token = (Get-Csrf $staffListBeforeDelete.Content); action = 'delete'; user_id = $librarianId }
Assert-Condition ($deletedStaff.Content.Contains('permanently removed') -and -not $deletedStaff.Content.Contains('librarian@example.test')) 'administrator can permanently remove a librarian'
$removedLoginSession = New-WebSession
$removedLoginPage = Invoke-App '/login.php' $removedLoginSession
$removedLoginCsrf = Get-Csrf $removedLoginPage.Content
$removedLogin = Invoke-App '/login.php' $removedLoginSession 'POST' @{
    csrf_token = $removedLoginCsrf
    email = 'librarian@example.test'
    password = $selfServicePassword
}
Assert-Condition ($removedLogin.Content.Contains('Email or password is incorrect')) 'permanently removed librarian cannot sign in'

Write-Output "PASS: $script:passed HTTP smoke checks completed against the isolated test database."
